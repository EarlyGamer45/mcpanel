#!/usr/bin/env bash
#
# Tests for deploy/lib/address.sh (domain/IP and HTTP/HTTPS choice).
#
#   bash tests/deploy-address.sh
#
# The pure part (validation, APP_URL, cookie settings, healthcheck URL) runs
# anywhere. The vhost part runs only as root on a machine with Nginx: it
# renders the real template into /etc/nginx and checks it with `nginx -t`
# (systemctl is stubbed, nothing is reloaded). Run it on a test VM only.
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0
FAIL=0

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
check() { local name="$1"; shift; if "$@"; then ok "${name}"; else fail "${name}"; fi; }

# Runs addr_resolve in a clean, non-interactive subshell with the given
# variables and prints "APP_URL SESSION_SECURE TRUST_PROXY HTTPS_MODE" (or
# "ERROR" when it refuses the input).
resolve() {
  (
    unset ADDRESS_MODE DOMAIN SERVER_IP HTTP_PORT HTTPS_MODE LETSENCRYPT_EMAIL SKIP_TLS
    for assignment in "$@"; do export "${assignment?}"; done
    # shellcheck source=../deploy/lib/address.sh
    source "${ROOT}/deploy/lib/address.sh"
    addr_detect_ip() { printf '198.51.100.7'; }
    addr_ipv6_enabled() { true; }
    addr_resolve </dev/null >/dev/null 2>&1
    echo "${APP_URL} ${SESSION_SECURE} ${TRUST_PROXY} ${HTTPS_MODE}"
  ) || echo "ERROR"
}

expect() {
  local name="$1" expected="$2"; shift 2
  local got
  got="$(resolve "$@")"
  if [[ "${got}" == "${expected}" ]]; then ok "${name}"; else fail "${name}: expected '${expected}', got '${got}'"; fi
}

echo "address resolution"
expect "domain + letsencrypt -> https, secure cookie" \
  "https://panel.example.com true false letsencrypt" DOMAIN=panel.example.com LETSENCRYPT_EMAIL=a@b.it
expect "domain uppercase and trailing dot normalised" \
  "https://panel.example.com true false letsencrypt" DOMAIN=Panel.Example.COM. LETSENCRYPT_EMAIL=a@b.it
expect "domain unattended without e-mail -> http (old behaviour, honest URL)" \
  "http://panel.example.com auto false off" DOMAIN=panel.example.com
expect "domain with explicit letsencrypt but no e-mail is refused" \
  "ERROR" DOMAIN=panel.example.com HTTPS_MODE=letsencrypt
expect "domain + SKIP_TLS=1 -> proxy, trusts X-Forwarded-Proto" \
  "https://panel.example.com true true proxy" DOMAIN=panel.example.com SKIP_TLS=1
expect "domain + HTTPS off on port 8080" \
  "http://panel.example.com:8080 auto false off" DOMAIN=panel.example.com HTTPS_MODE=off HTTP_PORT=8080
expect "letsencrypt on a port other than 80 is refused" \
  "ERROR" DOMAIN=panel.example.com LETSENCRYPT_EMAIL=a@b.it HTTP_PORT=8080
expect "an IP given as DOMAIN is refused with a hint" \
  "ERROR" DOMAIN=203.0.113.10
expect "invalid domain is refused" \
  "ERROR" DOMAIN=bad_domain
expect "IP mode with explicit IPv4" \
  "http://203.0.113.10 auto false off" ADDRESS_MODE=ip SERVER_IP=203.0.113.10
expect "SERVER_IP alone implies IP mode" \
  "http://203.0.113.10 auto false off" SERVER_IP=203.0.113.10
expect "IP mode with custom port" \
  "http://203.0.113.10:8080 auto false off" ADDRESS_MODE=ip SERVER_IP=203.0.113.10 HTTP_PORT=8080
expect "IP mode detects the address when SERVER_IP is empty" \
  "http://198.51.100.7 auto false off" ADDRESS_MODE=ip
expect "IPv6 is bracketed in APP_URL" \
  "http://[2001:db8::10] auto false off" ADDRESS_MODE=ip SERVER_IP=2001:db8::10
expect "IPv6 given in brackets is accepted" \
  "http://[2001:db8::10]:8080 auto false off" ADDRESS_MODE=ip SERVER_IP=[2001:db8::10] HTTP_PORT=8080
expect "IP mode refuses HTTPS" \
  "ERROR" ADDRESS_MODE=ip SERVER_IP=203.0.113.10 HTTPS_MODE=letsencrypt
expect "invalid IPv4 octet is refused" \
  "ERROR" ADDRESS_MODE=ip SERVER_IP=203.0.113.300
expect "invalid port is refused" \
  "ERROR" ADDRESS_MODE=ip SERVER_IP=203.0.113.10 HTTP_PORT=70000
expect "unknown ADDRESS_MODE is refused" \
  "ERROR" ADDRESS_MODE=hostname
expect "nothing set and no terminal -> refused with instructions" \
  "ERROR"

echo "healthcheck URL used by update.sh"
health() {
  (
    # shellcheck source=../deploy/lib/address.sh
    source "${ROOT}/deploy/lib/address.sh"
    SITE_ADDRESS_MODE="$1" SITE_HTTPS_MODE="$2" SITE_HTTP_PORT="$3" APP_URL="$4" addr_local_health
  )
}
check "letsencrypt resolves the domain to 127.0.0.1" \
  test "$(health domain letsencrypt 80 https://panel.example.com)" == "https://panel.example.com/api/v1/health|--resolve|panel.example.com:443:127.0.0.1"
check "IP mode calls local Nginx with the Host header" \
  test "$(health ip off 8080 http://203.0.113.10:8080)" == "http://127.0.0.1:8080/api/v1/health|--header|Host: 203.0.113.10:8080"
check "IPv6 keeps brackets in the Host header" \
  test "$(health ip off 80 'http://[2001:db8::10]')" == "http://127.0.0.1:80/api/v1/health|--header|Host: [2001:db8::10]"
check "proxy mode bypasses the load balancer" \
  test "$(health domain proxy 80 https://panel.example.com)" == "http://127.0.0.1:80/api/v1/health|--header|Host: panel.example.com"
check "installations without SITE_* keep using APP_URL" \
  test "$(health '' '' '' https://panel.example.com)" == "https://panel.example.com/api/v1/health||"

echo ".env writing"
tmp_env="$(mktemp)"
printf 'APP_URL=https://old.example.com\nSESSION_SECURE=true\nTRUST_PROXY=false\nOTHER=keep\n' >"${tmp_env}"
(
  # shellcheck source=../deploy/lib/address.sh
  source "${ROOT}/deploy/lib/address.sh"
  ADDRESS_MODE=ip HTTPS_MODE=off HOST=203.0.113.10 HTTP_PORT=8080 APP_URL="http://203.0.113.10:8080" \
    SESSION_SECURE=auto TRUST_PROXY=false addr_write_env "${tmp_env}"
)
check "APP_URL replaced" grep -qx 'APP_URL=http://203.0.113.10:8080' "${tmp_env}"
check "SESSION_SECURE replaced" grep -qx 'SESSION_SECURE=auto' "${tmp_env}"
check "SITE_* appended" grep -qx 'SITE_ADDRESS_MODE=ip' "${tmp_env}"
check "unrelated keys untouched" grep -qx 'OTHER=keep' "${tmp_env}"
check "no duplicated keys" test "$(grep -c '^APP_URL=' "${tmp_env}")" -eq 1
rm -f "${tmp_env}"

if [[ ${EUID} -eq 0 ]] && command -v nginx >/dev/null 2>&1 && [[ "${DEPLOY_TEST_NGINX:-0}" == "1" ]]; then
  echo "nginx vhost (real nginx -t)"
  sock="$(mktemp -u /tmp/php-fpm-test.XXXX.sock)"
  render() {
    (
      # shellcheck source=../deploy/lib/address.sh
      source "${ROOT}/deploy/lib/address.sh"
      systemctl() { :; }
      unset ADDRESS_MODE DOMAIN SERVER_IP HTTP_PORT HTTPS_MODE LETSENCRYPT_EMAIL SKIP_TLS
      for assignment in "$@"; do export "${assignment?}"; done
      addr_resolve </dev/null >/dev/null 2>&1
      addr_install_vhost "${ROOT}" "${sock}" "ops-test" >/dev/null 2>&1
    )
  }
  vhost=/etc/nginx/sites-available/mcpanel.conf
  check "domain vhost passes nginx -t" render DOMAIN=panel.example.com HTTPS_MODE=off
  check "domain vhost server_name" grep -q 'server_name panel.example.com;' "${vhost}"
  check "no placeholder left" bash -c "! grep -q '__[A-Z_]*__' ${vhost}"
  check "IPv4 vhost passes nginx -t" render ADDRESS_MODE=ip SERVER_IP=203.0.113.10 HTTP_PORT=8080
  check "IPv4 vhost listens on 8080 as default_server" grep -q 'listen 8080 default_server;' "${vhost}"
  check "IPv4 vhost server_name is the IP" grep -q 'server_name 203.0.113.10;' "${vhost}"
  if [[ -f /proc/net/if_inet6 ]]; then
    check "IPv6 vhost passes nginx -t" render ADDRESS_MODE=ip SERVER_IP=2001:db8::10
    check "IPv6 vhost uses the catch-all name" grep -q 'server_name _;' "${vhost}"
  else
    check "no IPv6 on this host: listen [::] dropped" bash -c "! grep -q 'listen \\[::\\]' ${vhost}"
    check "no IPv6 on this host: IPv6 SERVER_IP refused" bash -c "! (source ${ROOT}/deploy/lib/address.sh; ADDRESS_MODE=ip SERVER_IP=2001:db8::10 addr_resolve </dev/null >/dev/null 2>&1)"
  fi
  # An installation made by the previous installer (vhost named after the
  # domain) must be replaced, not left enabled next to the new one.
  sed -e "s|__DOMAIN__|old.example.com|g; s|__APP_DIR__|${ROOT}|g; s|__PHP_FPM_SOCK__|${sock}|g; s|__ADMIN_PATH__|admin|g; s|listen \[::\]:80;||" \
    -e '1s|^|# Minecraft Hosting Platform - Nginx vhost (installed by deploy/install.sh)\n|' \
    <(grep -v '__SERVER_NAME__\|__HTTP_PORT__' "${ROOT}/deploy/nginx/mcpanel.conf" | sed 's|^    root |    server_name __DOMAIN__; listen 80;\n    root |') \
    >/etc/nginx/sites-available/old.example.com.conf
  ln -sfn /etc/nginx/sites-available/old.example.com.conf /etc/nginx/sites-enabled/old.example.com.conf
  check "re-run over an old installation passes nginx -t" render DOMAIN=panel.example.com HTTPS_MODE=off
  check "old domain-named vhost disabled" test ! -e /etc/nginx/sites-enabled/old.example.com.conf
  rm -f /etc/nginx/sites-available/old.example.com.conf
  check "only one mcpanel vhost enabled" test "$(find /etc/nginx/sites-enabled -name 'mcpanel*' | wc -l)" -eq 1
  rm -f /etc/nginx/sites-enabled/mcpanel.conf "${vhost}"
else
  echo "nginx vhost: skipped (needs root, nginx and DEPLOY_TEST_NGINX=1)"
fi

echo
echo "${PASS} passed, ${FAIL} failed"
[[ ${FAIL} -eq 0 ]]
