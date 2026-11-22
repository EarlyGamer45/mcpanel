#!/usr/bin/env bash
#
# Installs the licence server on Ubuntu Server 26.04 LTS (Nginx + PHP-FPM + SQLite).
#
# Three ways to publish it:
#
#   local   same machine as the panel; listens only on 127.0.0.1:PORT (default
#           8090). Nothing is exposed to the network. Panel setting:
#           LICENSE_SERVER=http://127.0.0.1:8090
#
#   domain  separate server reachable at https://DOMAIN with a Let's Encrypt
#           certificate (falls back to plain HTTP if certbot fails).
#
#   ip      separate server reachable at http://IP:PORT, optionally limited to
#           the panels' IP addresses (ALLOW_IPS). Responses are signed, but the
#           licence key travels in clear: prefer "domain" over the internet.
#
# Interactive by default. Non-interactive example:
#   sudo LICENSE_MODE=local bash deploy/install.sh
#   sudo LICENSE_MODE=domain LICENSE_DOMAIN=license.example.com LICENSE_EMAIL=me@example.com bash deploy/install.sh
#   sudo LICENSE_MODE=ip LICENSE_PORT=8090 ALLOW_IPS="203.0.113.10 203.0.113.11" bash deploy/install.sh
#
set -Eeuo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="${LICENSE_DIR:-/var/www/license-server}"
PHP_VERSION="${PHP_VERSION:-8.5}"
SITE_NAME="mch-license"
WEB_USER="www-data"

info() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ ${EUID} -eq 0 ]] || die "Run as root: sudo bash $0"
command -v apt-get >/dev/null || die "This installer supports Ubuntu/Debian only"

ask() {
    local prompt="$1" default="${2:-}" answer
    if [[ ! -t 0 ]]; then
        printf '%s' "${default}"
        return
    fi
    read -r -p "${prompt}${default:+ [${default}]}: " answer
    printf '%s' "${answer:-${default}}"
}

valid_port() { [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 1 && $1 <= 65535 )); }
valid_domain() { [[ "$1" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]; }
valid_ipv4() { [[ "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; }

# ------------------------------------------------------------------ choices
MODE="${LICENSE_MODE:-}"
if [[ -z "${MODE}" ]]; then
    echo "Where will the licence server run?"
    echo "  1) local  - on this machine, used only by the panel installed here (recommended)"
    echo "  2) domain - separate server with its own domain and HTTPS"
    echo "  3) ip     - separate server reachable by IP address and port (HTTP)"
    case "$(ask 'Choice' '1')" in
        1|local) MODE=local ;;
        2|domain) MODE=domain ;;
        3|ip) MODE=ip ;;
        *) die "Invalid choice" ;;
    esac
fi

PORT="${LICENSE_PORT:-}"
DOMAIN="${LICENSE_DOMAIN:-}"
EMAIL="${LICENSE_EMAIL:-}"
ALLOW="${ALLOW_IPS:-}"
case "${MODE}" in
    local)
        PORT="${PORT:-$(ask 'Local port' '8090')}"
        valid_port "${PORT}" || die "Invalid port: ${PORT}"
        LISTEN="127.0.0.1:${PORT}"
        SERVER_NAME="_"
        URL="http://127.0.0.1:${PORT}"
        ;;
    domain)
        DOMAIN="${DOMAIN:-$(ask 'Domain (e.g. license.example.com)')}"
        valid_domain "${DOMAIN}" || die "Invalid domain: ${DOMAIN}"
        EMAIL="${EMAIL:-$(ask 'Email for Let'"'"'s Encrypt (empty = no HTTPS)')}"
        PORT=80
        LISTEN="80"
        SERVER_NAME="${DOMAIN}"
        URL="http://${DOMAIN}"
        ;;
    ip)
        PORT="${PORT:-$(ask 'Port' '8090')}"
        valid_port "${PORT}" || die "Invalid port: ${PORT}"
        ALLOW="${ALLOW:-$(ask 'Panel IP addresses allowed to connect (space separated, empty = everyone)')}"
        for address in ${ALLOW}; do
            valid_ipv4 "${address}" || [[ "${address}" == *:* ]] || die "Invalid IP address: ${address}"
        done
        LISTEN="${PORT}"
        SERVER_NAME="_"
        SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
        URL="http://${SERVER_IP:-SERVER_IP}:${PORT}"
        ;;
    *) die "LICENSE_MODE must be local, domain or ip" ;;
esac

# ------------------------------------------------------------------ packages
info "Installing packages (nginx, PHP ${PHP_VERSION} FPM, SQLite)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
packages=(nginx "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-mbstring" curl)
[[ "${MODE}" == "domain" && -n "${EMAIL}" ]] && packages+=(certbot python3-certbot-nginx)
apt-get install -y -qq "${packages[@]}" >/dev/null
PHP_BIN="$(command -v "php${PHP_VERSION}" || command -v php)"
"${PHP_BIN}" -r 'exit(function_exists("sodium_crypto_sign_keypair") && extension_loaded("pdo_sqlite") ? 0 : 1);' \
    || die "PHP needs the sodium and pdo_sqlite extensions"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"

# ------------------------------------------------------------------ files
info "Copying the licence server to ${TARGET_DIR}"
mkdir -p "${TARGET_DIR}"
if [[ "${SOURCE_DIR}" != "${TARGET_DIR}" ]]; then
    for item in bin public src config.php README.md deploy; do
        [[ -e "${SOURCE_DIR}/${item}" ]] && cp -a "${SOURCE_DIR}/${item}" "${TARGET_DIR}/"
    done
fi
mkdir -p "${TARGET_DIR}/keys" "${TARGET_DIR}/storage/logs"
chown -R root:"${WEB_USER}" "${TARGET_DIR}"
find "${TARGET_DIR}" -type d -exec chmod 750 {} +
find "${TARGET_DIR}" -type f -exec chmod 640 {} +
chown -R "${WEB_USER}:${WEB_USER}" "${TARGET_DIR}/keys" "${TARGET_DIR}/storage"
chmod 700 "${TARGET_DIR}/keys"
chmod 770 "${TARGET_DIR}/storage" "${TARGET_DIR}/storage/logs"

if [[ ! -f "${TARGET_DIR}/keys/private.key" ]]; then
    info "Generating the Ed25519 signing keys"
    sudo -u "${WEB_USER}" "${PHP_BIN}" "${TARGET_DIR}/bin/keygen.php" >/dev/null
else
    info "Keeping the existing signing keys"
fi
chmod 600 "${TARGET_DIR}/keys/private.key"
chmod 640 "${TARGET_DIR}/keys/public.key"
PUBLIC_KEY="$(tr -d '[:space:]' < "${TARGET_DIR}/keys/public.key")"

# Create the database now, owned by the web user.
sudo -u "${WEB_USER}" "${PHP_BIN}" "${TARGET_DIR}/bin/list.php" >/dev/null

# ------------------------------------------------------------------ nginx
info "Configuring Nginx (${MODE}, listen ${LISTEN})"
ALLOW_RULES=""
if [[ "${MODE}" == "ip" && -n "${ALLOW}" ]]; then
    for address in ${ALLOW}; do
        ALLOW_RULES+="        allow ${address};"$'\n'
    done
    ALLOW_RULES+="        allow 127.0.0.1;"$'\n'"        deny all;"$'\n'
fi
LISTEN_LINES="    listen ${LISTEN};"
if [[ "${MODE}" != "local" && -f /proc/net/if_inet6 ]]; then
    LISTEN_LINES+=$'\n'"    listen [::]:${PORT};"
fi

cat > "/etc/nginx/sites-available/${SITE_NAME}.conf" <<NGINX
# Licence server (${MODE}) - generated by license-server/deploy/install.sh
server {
${LISTEN_LINES}
    server_name ${SERVER_NAME};
    root ${TARGET_DIR}/public;
    index index.php;

    access_log /var/log/nginx/${SITE_NAME}.access.log;
    error_log /var/log/nginx/${SITE_NAME}.error.log;

    client_max_body_size 16k;
    add_header X-Content-Type-Options nosniff always;

    location / {
${ALLOW_RULES}        try_files \$uri /index.php?\$query_string;
    }

    location = /index.php {
${ALLOW_RULES}        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCKET};
    }

    location ~ \.php\$ { return 404; }
    location ~ /\.(?!well-known) { deny all; }
}
NGINX
ln -sf "/etc/nginx/sites-available/${SITE_NAME}.conf" "/etc/nginx/sites-enabled/${SITE_NAME}.conf"
if ! nginx -t 2>/tmp/mch-license-nginx.log; then
    rm -f "/etc/nginx/sites-enabled/${SITE_NAME}.conf"
    cat /tmp/mch-license-nginx.log >&2
    die "Nginx configuration test failed (port ${PORT} already in use?). Nothing was enabled."
fi
systemctl enable --now "php${PHP_VERSION}-fpm" nginx >/dev/null 2>&1
systemctl reload nginx

if [[ "${MODE}" != "local" ]] && command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    if [[ -n "${ALLOW}" ]]; then
        for address in ${ALLOW}; do ufw allow from "${address}" to any port "${PORT}" proto tcp >/dev/null; done
    else
        ufw allow "${PORT}/tcp" >/dev/null
    fi
    [[ "${MODE}" == "domain" ]] && ufw allow 443/tcp >/dev/null
fi

if [[ "${MODE}" == "domain" && -n "${EMAIL}" ]]; then
    info "Requesting the HTTPS certificate"
    if certbot --nginx -d "${DOMAIN}" -m "${EMAIL}" --agree-tos --non-interactive --redirect >/dev/null 2>&1; then
        URL="https://${DOMAIN}"
    else
        warn "certbot failed: the server stays on HTTP. Check DNS and port 80, then run: certbot --nginx -d ${DOMAIN}"
    fi
fi

# ------------------------------------------------------------------ check
health() { curl -fsSL --max-time 10 "$@" 2>/dev/null | grep -q '"ok":true'; }
if [[ "${MODE}" == "domain" ]]; then
    if health "${URL}/api/v1/health" || health -H "Host: ${DOMAIN}" "http://127.0.0.1/api/v1/health"; then
        info "Licence server is answering"
    else
        warn "Health check failed: check DNS for ${DOMAIN} and /var/log/nginx/${SITE_NAME}.error.log"
    fi
elif health "http://127.0.0.1:${PORT}/api/v1/health"; then
    info "Licence server is answering on ${URL}"
else
    warn "Health check failed on port ${PORT}: see /var/log/nginx/${SITE_NAME}.error.log"
fi

cat <<DONE

------------------------------------------------------------------------------
 Licence server installed in ${TARGET_DIR}

 Panel settings (copy them into the panel .env, or run deploy/set-license.sh
 from the panel folder):

   LICENSE_SERVER=${URL}
   LICENSE_PUBLIC_KEY=${PUBLIC_KEY}

 Issue a licence (run as ${WEB_USER} so the database stays writable):

   cd ${TARGET_DIR}
   sudo -u ${WEB_USER} php bin/issue.php standard 365 1     # 1 year, 1 panel
   sudo -u ${WEB_USER} php bin/issue.php standard 0 1       # lifetime
   sudo -u ${WEB_USER} php bin/list.php

 Back up ${TARGET_DIR}/keys/private.key offline: without it no licence can be
 verified again.
------------------------------------------------------------------------------
DONE
