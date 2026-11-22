#!/usr/bin/env bash
#
# Connects the panel to a licence server and (optionally) activates a key.
#
#   sudo bash deploy/set-license.sh
#   sudo LICENSE_SERVER=http://127.0.0.1:8090 LICENSE_KEY=MCH-XXXX-XXXX-XXXX-XXXX bash deploy/set-license.sh
#
# When the licence server is installed on this same machine the public key is
# read from /var/www/license-server/keys/public.key automatically.
#
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${APP_DIR}/.env"
LOCAL_LICENSE_DIR="${LICENSE_DIR:-/var/www/license-server}"
PHP_VERSION="${PHP_VERSION:-8.5}"
# shellcheck source=deploy/lib/address.sh
source "${APP_DIR}/deploy/lib/address.sh"

die() { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }

[[ ${EUID} -eq 0 ]] || die "Run as root: sudo bash $0"
[[ -f "${ENV_FILE}" ]] || die "${ENV_FILE} not found: install the panel first"

ask() {
    local prompt="$1" default="${2:-}" answer
    if [[ ! -t 0 ]]; then
        printf '%s' "${default}"
        return
    fi
    read -r -p "${prompt}${default:+ [${default}]}: " answer
    printf '%s' "${answer:-${default}}"
}

current() { grep -E "^$1=" "${ENV_FILE}" | head -n1 | cut -d= -f2- || true; }

LOCAL_KEY=""
if [[ -r "${LOCAL_LICENSE_DIR}/keys/public.key" ]]; then
    LOCAL_KEY="$(tr -d '[:space:]' < "${LOCAL_LICENSE_DIR}/keys/public.key")"
fi
LOCAL_URL=""
if [[ -f /etc/nginx/sites-available/mch-license.conf ]]; then
    port="$(grep -oE 'listen 127\.0\.0\.1:[0-9]+' /etc/nginx/sites-available/mch-license.conf | head -n1 | cut -d: -f2 || true)"
    [[ -n "${port}" ]] && LOCAL_URL="http://127.0.0.1:${port}"
fi

SERVER="${LICENSE_SERVER:-}"
if [[ -z "${SERVER}" ]]; then
    default="$(current LICENSE_SERVER)"
    SERVER="$(ask 'Licence server URL' "${default:-${LOCAL_URL}}")"
fi
[[ "${SERVER}" =~ ^https?://[^[:space:]]+$ ]] || die "Invalid URL: ${SERVER} (example: http://127.0.0.1:8090)"
SERVER="${SERVER%/}"

PUBLIC_KEY="${LICENSE_PUBLIC_KEY:-}"
if [[ -z "${PUBLIC_KEY}" ]]; then
    default="$(current LICENSE_PUBLIC_KEY)"
    PUBLIC_KEY="$(ask 'Licence server public key (LICENSE_PUBLIC_KEY)' "${LOCAL_KEY:-${default}}")"
fi
[[ "${PUBLIC_KEY}" =~ ^[A-Za-z0-9+/]{43}=$ ]] || die "The public key must be the 44-character base64 string printed by the licence server installer"

info "Checking ${SERVER}"
if ! curl -fsS --max-time 10 "${SERVER}/api/v1/health" | grep -q '"ok":true'; then
    die "The licence server does not answer on ${SERVER}/api/v1/health"
fi

cp -p "${ENV_FILE}" "${ENV_FILE}.bak.$(date +%Y%m%d%H%M%S)"
addr_set_env "${ENV_FILE}" LICENSE_SERVER "${SERVER}"
addr_set_env "${ENV_FILE}" LICENSE_PUBLIC_KEY "${PUBLIC_KEY}"
info "Saved LICENSE_SERVER and LICENSE_PUBLIC_KEY in .env"
systemctl reload "php${PHP_VERSION}-fpm" 2>/dev/null || true
systemctl restart mcpanel-worker 2>/dev/null || true

PHP_BIN="$(command -v "php${PHP_VERSION}" || command -v php)"
KEY="${LICENSE_KEY:-}"
if [[ -z "${KEY}" ]]; then
    KEY="$(ask 'Licence key to activate now (empty = later from Admin -> Settings)' '')"
fi
if [[ -n "${KEY}" ]]; then
    info "Activating the licence"
    sudo -u www-data "${PHP_BIN}" "${APP_DIR}/bin/console" license:activate "${KEY}" "${SERVER}"
fi
sudo -u www-data "${PHP_BIN}" "${APP_DIR}/bin/console" license:status || true
