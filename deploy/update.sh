#!/usr/bin/env bash
#
# Minecraft Hosting Platform - safe in-place update
#
#   sudo bash deploy/update.sh [--no-backup]
#
# 1. Puts the site in maintenance mode (customers keep their servers)
# 2. Dumps the database and archives the current release
# 3. Pulls the new code (git) or uses the already-uploaded files
# 4. Runs migrations, clears caches, restarts workers and PHP-FPM
# 5. Runs a healthcheck; on failure prints the rollback instructions
#
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-$(command -v php8.5 || command -v php)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/mcpanel}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DO_BACKUP=1
[[ "${1:-}" == "--no-backup" ]] && DO_BACKUP=0

if [[ ${EUID} -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

cd "${APP_DIR}"

set -a
# shellcheck disable=SC1091
source .env
set +a

# Keep a copy of the encryption keys outside the panel folder (see install.sh).
if [[ ! -f /etc/mcpanel/keys.env && -n "${APP_KEY:-}" && -n "${BLIND_INDEX_KEY:-}" ]]; then
  install -d -m 0700 /etc/mcpanel
  printf 'APP_KEY=%s\nBLIND_INDEX_KEY=%s\n' "${APP_KEY}" "${BLIND_INDEX_KEY}" > /etc/mcpanel/keys.env
  chmod 0600 /etc/mcpanel/keys.env
  echo "==> Saved a copy of the encryption keys in /etc/mcpanel/keys.env"
fi

maintenance_on() {
  "${PHP_BIN}" bin/console about >/dev/null 2>&1 || true
  "${PHP_BIN}" -r '
    require "bootstrap/autoload.php";
    App\Core\App::boot(getcwd());
    App\Core\App::setSetting("maintenance_mode", true);
  '
}
maintenance_off() {
  "${PHP_BIN}" -r '
    require "bootstrap/autoload.php";
    App\Core\App::boot(getcwd());
    App\Core\App::setSetting("maintenance_mode", false);
  '
}

echo "==> Maintenance mode on"
maintenance_on

if [[ ${DO_BACKUP} -eq 1 ]]; then
  mkdir -p "${BACKUP_DIR}"
  echo "==> Backing up database to ${BACKUP_DIR}/db-${STAMP}.sql.gz"
  if [[ "${DB_DRIVER:-mysql}" == "mysql" ]]; then
    MYSQL_PWD="${DB_PASSWORD:-}" mysqldump --single-transaction --quick --routines \
      -h "${DB_HOST:-127.0.0.1}" -u "${DB_USERNAME:-mchosting}" "${DB_DATABASE:-mchosting}" \
      | gzip > "${BACKUP_DIR}/db-${STAMP}.sql.gz"
  else
    cp "${DB_SQLITE_PATH:-storage/database.sqlite}" "${BACKUP_DIR}/db-${STAMP}.sqlite"
  fi
  echo "==> Archiving current release"
  tar -czf "${BACKUP_DIR}/code-${STAMP}.tar.gz" \
    --exclude='storage/logs/*' --exclude='storage/cache/*' \
    -C "$(dirname "${APP_DIR}")" "$(basename "${APP_DIR}")"
fi

if [[ -d .git ]]; then
  echo "==> Pulling new code"
  sudo -u www-data git pull --ff-only
fi

echo "==> Migrating"
sudo -u www-data "${PHP_BIN}" bin/console migrate

echo "==> Clearing caches"
rm -f storage/cache/*.php bootstrap/cache/*.php || true
chown -R www-data:www-data storage bootstrap/cache
chmod -R 750 storage bootstrap/cache

echo "==> Restarting services"
systemctl restart mcpanel-worker.service
systemctl reload php8.5-fpm 2>/dev/null || systemctl restart php8.5-fpm
systemctl reload nginx

echo "==> Healthcheck"
# shellcheck source=lib/address.sh
source "${APP_DIR}/deploy/lib/address.sh"
# Hit this server's own Nginx using the address chosen at install time
# (domain or IP, HTTP or HTTPS), not $(hostname -f).
IFS='|' read -r HEALTH_URL HEALTH_OPT HEALTH_VAL <<<"$(addr_local_health)"
HEALTH_ARGS=()
[[ -n "${HEALTH_OPT}" ]] && HEALTH_ARGS=("${HEALTH_OPT}" "${HEALTH_VAL}")
echo "    ${HEALTH_URL}"
for attempt in 1 2 3 4 5; do
  if curl -fsS --max-time 5 "${HEALTH_ARGS[@]}" "${HEALTH_URL}" >/dev/null; then
    echo "Healthcheck OK"
    break
  fi
  sleep 3
  if [[ ${attempt} -eq 5 ]]; then
    echo "Healthcheck FAILED. Roll back with:" >&2
    echo "  tar -xzf ${BACKUP_DIR}/code-${STAMP}.tar.gz -C $(dirname "${APP_DIR}")" >&2
    echo "  gunzip -c ${BACKUP_DIR}/db-${STAMP}.sql.gz | mysql -u ${DB_USERNAME} -p ${DB_DATABASE}" >&2
    maintenance_off
    exit 1
  fi
done

maintenance_off
echo "Update completed. Backups: ${BACKUP_DIR}"
