#!/usr/bin/env bash
#
# Minecraft Hosting Platform - change how the site is reached, after install.
#
#   Interactive:
#     sudo bash deploy/set-address.sh
#
#   From IP/HTTP to a domain with a Let's Encrypt certificate:
#     sudo DOMAIN=panel.example.com LETSENCRYPT_EMAIL=you@example.com bash deploy/set-address.sh
#
#   Back to IP over HTTP (e.g. the domain expired):
#     sudo ADDRESS_MODE=ip bash deploy/set-address.sh
#
# Same options as deploy/install.sh (ADDRESS_MODE, DOMAIN, SERVER_IP,
# HTTP_PORT, HTTPS_MODE, LETSENCRYPT_EMAIL, SKIP_TLS). It rewrites APP_URL,
# SESSION_SECURE and TRUST_PROXY in .env, regenerates the Nginx vhost, asks
# Let's Encrypt for a certificate when needed and reloads the services.
# Database, customers and Pterodactyl servers are not touched.
#
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Run as root: sudo bash deploy/set-address.sh" >&2
  exit 1
fi

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
ENV_FILE="${APP_DIR}/.env"
[[ -f "${ENV_FILE}" ]] || { echo "${ENV_FILE} not found: run deploy/install.sh first." >&2; exit 1; }

# shellcheck source=lib/address.sh
source "${APP_DIR}/deploy/lib/address.sh"

env_value() { grep -E "^$1=" "${ENV_FILE}" | tail -n1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

OLD_URL="$(env_value APP_URL)"
ADMIN_PATH="${ADMIN_PATH:-$(env_value ADMIN_PATH)}"
ADMIN_PATH="${ADMIN_PATH:-admin}"
# Keep the current port unless a new one is given.
HTTP_PORT="${HTTP_PORT:-$(env_value SITE_HTTP_PORT)}"

PHP_FPM_SOCK="/run/php/php8.5-fpm.sock"
if [[ ! -S "${PHP_FPM_SOCK}" ]]; then
  PHP_FPM_SOCK="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | head -n1 || true)"
fi
[[ -n "${PHP_FPM_SOCK}" ]] || { echo "PHP-FPM socket not found." >&2; exit 1; }

echo "Current address: ${OLD_URL:-unknown}"
echo "==> New address"
addr_resolve
addr_summary
if addr_is_tty; then
  confirm="$(addr_ask "Apply? (y/n)" "y")"
  [[ "${confirm,,}" == y* ]] || { echo "Aborted."; exit 1; }
fi

cp -p "${ENV_FILE}" "${ENV_FILE}.bak-$(date -u +%Y%m%dT%H%M%SZ)"
addr_write_env "${ENV_FILE}"

echo "==> Nginx"
addr_install_vhost "${APP_DIR}" "${PHP_FPM_SOCK}" "${ADMIN_PATH}"
addr_open_firewall

if ! addr_request_certificate; then
  # shellcheck disable=SC2034 # read by addr_compute_url (sourced)
  HTTPS_MODE="off"
  addr_compute_url
  addr_write_env "${ENV_FILE}"
fi

echo "==> Reloading services"
systemctl reload php8.5-fpm 2>/dev/null || systemctl restart php8.5-fpm 2>/dev/null || true
systemctl restart mcpanel-worker.service 2>/dev/null || true

cat <<EOF

Address updated.

$(addr_summary)

Check also:
  - Stripe/PayPal webhooks: ${APP_URL}/webhooks/stripe and ${APP_URL}/webhooks/paypal
    (both providers require HTTPS);
  - the license: it is bound to the host name, re-activate it from the admin
    panel if the license server reports a domain mismatch;
  - the admin "Diagnostics" page, section "Address and HTTPS".
EOF
if [[ -n "${OLD_URL}" && "${OLD_URL}" != "${APP_URL}" ]]; then
  echo "Links in e-mails already sent still point to ${OLD_URL}."
fi
