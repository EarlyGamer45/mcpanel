#!/usr/bin/env bash
#
# Minecraft Hosting Platform - Ubuntu 26.04 LTS installer
#
#   Interactive (asks domain or IP, and HTTPS):
#     sudo bash deploy/install.sh
#
#   Domain with a Let's Encrypt certificate:
#     sudo DOMAIN=panel.example.com LETSENCRYPT_EMAIL=you@example.com bash deploy/install.sh
#
#   IP address over plain HTTP (testing, LAN, VPN):
#     sudo ADDRESS_MODE=ip bash deploy/install.sh
#     sudo ADDRESS_MODE=ip SERVER_IP=203.0.113.10 HTTP_PORT=8080 bash deploy/install.sh
#
# Installs Nginx, PHP 8.5 FPM, MariaDB 11.8, creates the database and the
# application .env, runs migrations, installs the systemd worker/scheduler and
# the Nginx vhost. It never touches an existing Pterodactyl installation: the
# panel is reached through its APIs only.
#
# Options (environment variables):
#   ADDRESS_MODE      domain | ip   (default: domain when DOMAIN is set)
#   DOMAIN            public hostname, DNS must already point here (domain mode)
#   SERVER_IP         address customers will use (ip mode, default: detected)
#   HTTP_PORT         default 80 (e.g. 8080 to share the server with another site)
#   HTTPS_MODE        letsencrypt | proxy | off
#                     default: letsencrypt for a domain, off for an IP
#   LETSENCRYPT_EMAIL required by HTTPS_MODE=letsencrypt
#   SKIP_TLS          1 = HTTPS_MODE=proxy (TLS on an external load balancer)
#   APP_DIR           default /var/www/mcpanel
#   DB_NAME           default mchosting
#   DB_USER           default mchosting
#   ADMIN_PATH        default admin (change it in production)
#
# The address can be changed later (e.g. from IP to domain + HTTPS) with
# deploy/set-address.sh, without reinstalling.
#
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Run as root: sudo bash deploy/install.sh" >&2
  exit 1
fi

APP_DIR="${APP_DIR:-/var/www/mcpanel}"
DB_NAME="${DB_NAME:-mchosting}"
DB_USER="${DB_USER:-mchosting}"
ADMIN_PATH="${ADMIN_PATH:-admin}"

if [[ ! -f "${APP_DIR}/bin/console" ]]; then
  echo "Application not found in ${APP_DIR}. Upload the release there first." >&2
  exit 1
fi
if ! grep -qi 'ubuntu' /etc/os-release; then
  echo "This installer targets Ubuntu (26.04 LTS)." >&2
  exit 1
fi

# shellcheck source=lib/address.sh
source "${APP_DIR}/deploy/lib/address.sh"

echo "==> Site address"
addr_resolve
addr_summary
if [[ "${ADDRESS_MODE}" == "ip" ]]; then
  echo "!! IP mode: the site runs over plain HTTP. Passwords and customer data are not"
  echo "   encrypted in transit, and Stripe/PayPal webhooks need HTTPS. Switch later with"
  echo "   deploy/set-address.sh once you have a domain."
elif [[ "${HTTPS_MODE}" == "off" ]]; then
  echo "!! HTTPS disabled: enable it later with deploy/set-address.sh."
fi
if addr_is_tty; then
  confirm="$(addr_ask "Continue with these settings? (y/n)" "y")"
  [[ "${confirm,,}" == y* ]] || { echo "Aborted."; exit 1; }
fi

export DEBIAN_FRONTEND=noninteractive
echo "==> Installing packages"
apt-get update -y
apt-get install -y --no-install-recommends \
  nginx mariadb-server mariadb-client \
  php8.5-cli php8.5-fpm php8.5-mysql php8.5-mbstring php8.5-xml \
  php8.5-curl php8.5-zip php8.5-intl php8.5-gd \
  unzip curl ca-certificates sudo cron

PHP_BIN="$(command -v php8.5 || command -v php)"
PHP_FPM_SOCK="/run/php/php8.5-fpm.sock"
if [[ ! -S "${PHP_FPM_SOCK}" ]]; then
  PHP_FPM_SOCK="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | head -n1 || true)"
fi
if [[ -z "${PHP_FPM_SOCK}" ]]; then
  echo "PHP-FPM socket not found. Is php8.5-fpm running?" >&2
  exit 1
fi

"${PHP_BIN}" -r 'foreach (["pdo_mysql","mbstring","curl","sodium","intl","zip"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing PHP extension: $e\n"); exit(1); } }'

echo "==> Configuring MariaDB"
systemctl enable --now mariadb
DB_PASS="$("${PHP_BIN}" -r 'echo bin2hex(random_bytes(18));')"
mysql --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> Writing environment"
cd "${APP_DIR}"
if [[ ! -f .env ]]; then
  cp .env.example .env
fi
sed -i '/^\[TEMPLATE\]/d' .env
# Encryption keys: e-mails and personal data in the database are encrypted with
# them. They are also kept in /etc/mcpanel/keys.env, outside the panel folder,
# so deleting and reinstalling the panel files does not lose them.
KEYS_FILE="/etc/mcpanel/keys.env"
APP_KEY="$(grep -E '^APP_KEY=.' .env | head -n1 | cut -d= -f2- || true)"
BLIND_KEY="$(grep -E '^BLIND_INDEX_KEY=.' .env | head -n1 | cut -d= -f2- || true)"
if [[ -f "${KEYS_FILE}" ]]; then
  [[ -n "${APP_KEY}" ]] || APP_KEY="$(grep -E '^APP_KEY=.' "${KEYS_FILE}" | head -n1 | cut -d= -f2- || true)"
  [[ -n "${BLIND_KEY}" ]] || BLIND_KEY="$(grep -E '^BLIND_INDEX_KEY=.' "${KEYS_FILE}" | head -n1 | cut -d= -f2- || true)"
fi
if [[ -z "${APP_KEY}" || -z "${BLIND_KEY}" ]]; then
  EXISTING_USERS="$(mysql --protocol=socket -N -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.users" 2>/dev/null || echo 0)"
  if [[ "${EXISTING_USERS:-0}" =~ ^[0-9]+$ ]] && (( EXISTING_USERS > 0 )) && [[ "${FORCE_NEW_KEYS:-0}" != "1" ]]; then
    echo "[x] The database '${DB_NAME}' already has ${EXISTING_USERS} accounts, but the encryption keys are missing." >&2
    echo "    New keys would make e-mails and personal data unreadable." >&2
    echo "    Put the old APP_KEY and BLIND_INDEX_KEY in ${APP_DIR:-.}/.env (or ${KEYS_FILE}) and run again." >&2
    echo "    To continue anyway with new keys (e-mails must then be set again with" >&2
    echo "    'php bin/console user:email'), run: sudo FORCE_NEW_KEYS=1 bash deploy/install.sh" >&2
    exit 1
  fi
fi
[[ -n "${APP_KEY}" ]] || APP_KEY="$("${PHP_BIN}" -r 'echo base64_encode(random_bytes(32));')"
[[ -n "${BLIND_KEY}" ]] || BLIND_KEY="$("${PHP_BIN}" -r 'echo base64_encode(random_bytes(32));')"
install -d -m 0700 /etc/mcpanel
printf 'APP_KEY=%s\nBLIND_INDEX_KEY=%s\n' "${APP_KEY}" "${BLIND_KEY}" > "${KEYS_FILE}"
chmod 0600 "${KEYS_FILE}"
addr_set_env .env APP_ENV "production"
addr_set_env .env APP_DEBUG "false"
addr_set_env .env APP_KEY "${APP_KEY}"
addr_set_env .env BLIND_INDEX_KEY "${BLIND_KEY}"
addr_set_env .env DB_DRIVER "mysql"
addr_set_env .env DB_HOST "127.0.0.1"
addr_set_env .env DB_DATABASE "${DB_NAME}"
addr_set_env .env DB_USERNAME "${DB_USER}"
addr_set_env .env DB_PASSWORD "${DB_PASS}"
addr_set_env .env ADMIN_PATH "${ADMIN_PATH}"
addr_write_env .env
chmod 640 .env
chown root:www-data .env

mkdir -p storage/cache storage/logs storage/invoices bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 750 storage bootstrap/cache
chmod +x bin/console bin/worker deploy/update.sh deploy/set-address.sh

echo "==> Running migrations"
sudo -u www-data "${PHP_BIN}" bin/console migrate

echo "==> Installing systemd units"
install -m 0644 deploy/systemd/mcpanel-worker.service /etc/systemd/system/mcpanel-worker.service
install -m 0644 deploy/systemd/mcpanel-scheduler.service /etc/systemd/system/mcpanel-scheduler.service
install -m 0644 deploy/systemd/mcpanel-scheduler.timer /etc/systemd/system/mcpanel-scheduler.timer
sed -i "s|/var/www/mcpanel|${APP_DIR}|g" /etc/systemd/system/mcpanel-*.service
sed -i "s|/usr/bin/php|${PHP_BIN}|g" /etc/systemd/system/mcpanel-*.service
systemctl daemon-reload
systemctl enable --now mcpanel-worker.service
systemctl enable --now mcpanel-scheduler.timer

echo "==> Installing Nginx vhost"
addr_install_vhost "${APP_DIR}" "${PHP_FPM_SOCK}" "${ADMIN_PATH}"
addr_open_firewall

if ! addr_request_certificate; then
  # No certificate: keep the site usable over HTTP instead of pointing
  # APP_URL and the session cookie at an HTTPS that does not exist.
  HTTPS_MODE="off"
  addr_compute_url
  addr_write_env .env
fi
systemctl reload php8.5-fpm 2>/dev/null || true

cat <<EOF

Installation complete.

$(addr_summary)

  1. Open ${APP_URL}/install and create the administrator
     (the wizard seeds catalog, legal pages and knowledge base).
  2. Set ADMIN_PATH in ${APP_DIR}/.env to an unguessable path and
     reload PHP-FPM: systemctl reload php8.5-fpm
  3. Configure Pterodactyl, Stripe/PayPal and SMTP in ${APP_DIR}/.env
     (see docs/it/03-install-guide.md), then:
       systemctl restart mcpanel-worker php8.5-fpm
  4. Verify: curl -fsS ${APP_URL}/api/v1/health
EOF
if [[ "${HTTPS_MODE}" == "off" ]]; then
  cat <<EOF

  !! The site is on plain HTTP. Online payments (Stripe/PayPal webhooks)
     need HTTPS. To add a domain and a certificate later:
       sudo DOMAIN=panel.example.com LETSENCRYPT_EMAIL=you@example.com \\
            bash ${APP_DIR}/deploy/set-address.sh
EOF
fi
cat <<EOF

Services: mcpanel-worker (queue), mcpanel-scheduler.timer (renewals, suspensions).
Logs:     journalctl -u mcpanel-worker -f   |   ${APP_DIR}/storage/logs/
EOF
