# shellcheck shell=bash
#
# Minecraft Hosting Platform - how the site is reached (domain or IP, HTTP or
# HTTPS). Sourced by deploy/install.sh, deploy/set-address.sh and
# deploy/update.sh; it only defines functions and never runs anything itself.
#
# Inputs (environment variables, all optional; asked interactively when the
# script runs in a terminal and a required value is missing):
#
#   ADDRESS_MODE       domain | ip           (default: domain if DOMAIN is set,
#                                             ip if SERVER_IP is set, else asked)
#   DOMAIN             public hostname, e.g. panel.example.com   (domain mode)
#   SERVER_IP          IPv4/IPv6 the customers type in the browser (ip mode,
#                      default: the address of the default route)
#   HTTP_PORT          port Nginx listens on for HTTP (default 80)
#   HTTPS_MODE         letsencrypt | proxy | off
#                        letsencrypt  certificate from Let's Encrypt (domain only)
#                        proxy        TLS terminated by a load balancer/CDN in
#                                     front of this server (domain only)
#                        off          plain HTTP
#                      default: letsencrypt for a domain (off when running
#                      unattended without LETSENCRYPT_EMAIL), off for an IP
#   LETSENCRYPT_EMAIL  e-mail for Let's Encrypt (required by letsencrypt)
#   SKIP_TLS=1         legacy alias of HTTPS_MODE=proxy
#
# Outputs (globals set by addr_resolve / addr_apply):
#   ADDRESS_MODE HTTPS_MODE HOST HTTP_PORT APP_URL SESSION_SECURE TRUST_PROXY
#
# Site settings written to .env so later runs and update.sh know the choice:
#   SITE_ADDRESS_MODE, SITE_HTTPS_MODE, SITE_HTTP_PORT

ADDR_VHOST_NAME="mcpanel.conf"
ADDR_VHOST_MARKER="# managed-by: mcpanel deploy/lib/address.sh"

addr_is_tty() { [[ -t 0 && -t 1 ]]; }

addr_die() { echo "ERROR: $*" >&2; exit 1; }

addr_valid_ipv4() {
  local ip="$1" part
  [[ "${ip}" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
  IFS='.' read -r -a parts <<<"${ip}"
  for part in "${parts[@]}"; do
    [[ "${part}" =~ ^(0|[1-9][0-9]{0,2})$ ]] || return 1
    (( part <= 255 )) || return 1
  done
}

addr_valid_ipv6() {
  local ip="$1"
  [[ "${ip}" == *:* ]] || return 1
  [[ "${ip}" =~ ^[0-9A-Fa-f:.]+$ ]] || return 1
  # at most one "::" and no ":::"
  [[ "${ip}" != *:::* ]] || return 1
  local doubles="${ip//[^:]/}"
  [[ $(grep -o '::' <<<"${ip}" | wc -l) -le 1 ]] || return 1
  (( ${#doubles} >= 2 && ${#doubles} <= 7 ))
}

addr_valid_ip() { addr_valid_ipv4 "$1" || addr_valid_ipv6 "$1"; }

addr_valid_domain() {
  local d="$1"
  (( ${#d} <= 253 )) || return 1
  addr_valid_ipv4 "${d}" && return 1
  [[ "${d}" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z][A-Za-z0-9-]{0,62}[A-Za-z0-9]$ ]]
}

addr_valid_port() {
  [[ "$1" =~ ^[0-9]{1,5}$ ]] && (( $1 >= 1 && $1 <= 65535 ))
}

addr_is_private_ip() {
  local ip="$1"
  [[ "${ip}" =~ ^10\. || "${ip}" =~ ^192\.168\. || "${ip}" =~ ^172\.(1[6-9]|2[0-9]|3[01])\. \
     || "${ip}" =~ ^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\. || "${ip}" =~ ^127\. \
     || "${ip,,}" =~ ^f[cd] || "${ip,,}" =~ ^fe80: || "${ip}" == "::1" ]]
}

# Address of the interface holding the default route (no external service).
addr_detect_ip() {
  local ip=""
  if command -v ip >/dev/null 2>&1; then
    ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") {print $(i+1); exit}}')"
  fi
  if [[ -z "${ip}" ]]; then
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  fi
  printf '%s' "${ip}"
}

addr_ask() {
  # addr_ask "Question" "default" -> echoes the answer
  local question="$1" default="${2:-}" answer
  if [[ -n "${default}" ]]; then
    read -r -p "${question} [${default}]: " answer
  else
    read -r -p "${question}: " answer
  fi
  printf '%s' "${answer:-${default}}"
}

# Validates and completes the inputs; asks what is missing when interactive.
addr_resolve() {
  ADDRESS_MODE="${ADDRESS_MODE:-}"
  DOMAIN="${DOMAIN:-}"
  SERVER_IP="${SERVER_IP:-}"
  HTTP_PORT="${HTTP_PORT:-80}"
  HTTPS_MODE="${HTTPS_MODE:-}"
  LETSENCRYPT_EMAIL="${LETSENCRYPT_EMAIL:-}"
  if [[ "${SKIP_TLS:-0}" == "1" && -z "${HTTPS_MODE}" ]]; then
    HTTPS_MODE="proxy"
  fi

  if [[ -z "${ADDRESS_MODE}" ]]; then
    if [[ -n "${DOMAIN}" ]]; then
      ADDRESS_MODE="domain"
    elif [[ -n "${SERVER_IP}" ]]; then
      ADDRESS_MODE="ip"
    elif addr_is_tty; then
      echo
      echo "How will customers reach the site?"
      echo "  1) Domain name (e.g. panel.example.com) - HTTPS available"
      echo "  2) IP address over HTTP - for testing or private networks"
      local choice
      choice="$(addr_ask "Choose 1 or 2" "1")"
      case "${choice}" in
        1|domain) ADDRESS_MODE="domain" ;;
        2|ip) ADDRESS_MODE="ip" ;;
        *) addr_die "invalid choice '${choice}'" ;;
      esac
    else
      addr_die "set DOMAIN=panel.example.com, or ADDRESS_MODE=ip (optionally SERVER_IP=...)"
    fi
  fi
  ADDRESS_MODE="${ADDRESS_MODE,,}"

  case "${ADDRESS_MODE}" in
    domain)
      if [[ -z "${DOMAIN}" ]] && addr_is_tty; then
        DOMAIN="$(addr_ask "Domain (DNS A/AAAA record must point to this server)" "")"
      fi
      DOMAIN="${DOMAIN,,}"
      DOMAIN="${DOMAIN%.}"
      [[ -n "${DOMAIN}" ]] || addr_die "DOMAIN is required in domain mode"
      if addr_valid_ip "${DOMAIN}"; then
        addr_die "'${DOMAIN}' is an IP address: use ADDRESS_MODE=ip SERVER_IP=${DOMAIN}"
      fi
      addr_valid_domain "${DOMAIN}" || addr_die "'${DOMAIN}' is not a valid domain name"
      HOST="${DOMAIN}"
      if [[ -z "${HTTPS_MODE}" ]]; then
        if addr_is_tty; then
          echo
          echo "HTTPS for ${DOMAIN}:"
          echo "  1) Let's Encrypt certificate on this server (recommended)"
          echo "  2) TLS handled by a load balancer / CDN in front of this server"
          echo "  3) No HTTPS (plain HTTP)"
          local tls
          tls="$(addr_ask "Choose 1, 2 or 3" "1")"
          case "${tls}" in
            1) HTTPS_MODE="letsencrypt" ;;
            2) HTTPS_MODE="proxy" ;;
            3) HTTPS_MODE="off" ;;
            *) addr_die "invalid choice '${tls}'" ;;
          esac
        elif [[ -n "${LETSENCRYPT_EMAIL}" ]]; then
          HTTPS_MODE="letsencrypt"
        else
          # Same outcome as the old installer without LETSENCRYPT_EMAIL, but
          # now APP_URL honestly says http:// instead of a missing https://.
          echo "!! LETSENCRYPT_EMAIL not set: installing over HTTP (set HTTPS_MODE explicitly to silence this)."
          HTTPS_MODE="off"
        fi
      fi
      ;;
    ip)
      if [[ -z "${SERVER_IP}" ]]; then
        local detected
        detected="$(addr_detect_ip)"
        if addr_is_tty; then
          SERVER_IP="$(addr_ask "IP address customers will use" "${detected}")"
        else
          SERVER_IP="${detected}"
        fi
      fi
      SERVER_IP="${SERVER_IP#[}"
      SERVER_IP="${SERVER_IP%]}"
      [[ -n "${SERVER_IP}" ]] || addr_die "could not detect the server IP: set SERVER_IP=..."
      addr_valid_ip "${SERVER_IP}" || addr_die "'${SERVER_IP}' is not a valid IPv4/IPv6 address"
      HOST="${SERVER_IP}"
      if addr_valid_ipv6 "${SERVER_IP}" && ! addr_ipv6_enabled; then
        addr_die "${SERVER_IP} is IPv6 but IPv6 is disabled on this server"
      fi
      if [[ -z "${HTTPS_MODE}" ]]; then
        HTTPS_MODE="off"
      elif [[ "${HTTPS_MODE,,}" != "off" ]]; then
        addr_die "HTTPS needs a domain name: the IP mode works over HTTP only (HTTPS_MODE=off)"
      fi
      if addr_is_private_ip "${SERVER_IP}"; then
        echo "!! ${SERVER_IP} is a private/local address: only devices on the same network (or VPN) can reach the site."
      fi
      ;;
    *)
      addr_die "ADDRESS_MODE must be 'domain' or 'ip' (got '${ADDRESS_MODE}')"
      ;;
  esac

  HTTPS_MODE="${HTTPS_MODE,,}"
  case "${HTTPS_MODE}" in letsencrypt|proxy|off) ;; *) addr_die "HTTPS_MODE must be letsencrypt, proxy or off" ;; esac

  if [[ -z "${HTTP_PORT}" ]] || ! addr_valid_port "${HTTP_PORT}"; then
    addr_die "HTTP_PORT must be a number between 1 and 65535"
  fi
  if [[ "${HTTPS_MODE}" == "letsencrypt" ]]; then
    [[ "${HTTP_PORT}" == "80" ]] || addr_die "Let's Encrypt validates on port 80: use HTTP_PORT=80 (or HTTPS_MODE=proxy/off)"
    if [[ -z "${LETSENCRYPT_EMAIL}" ]] && addr_is_tty; then
      LETSENCRYPT_EMAIL="$(addr_ask "E-mail for Let's Encrypt expiry notices" "")"
    fi
    if [[ -z "${LETSENCRYPT_EMAIL}" ]]; then
      addr_die "LETSENCRYPT_EMAIL is required for HTTPS_MODE=letsencrypt (or choose HTTPS_MODE=off)"
    fi
    [[ "${LETSENCRYPT_EMAIL}" == *@*.* ]] || addr_die "'${LETSENCRYPT_EMAIL}' is not a valid e-mail"
  fi

  addr_compute_url
}

# APP_URL, cookie and proxy settings from the current choice. Called again
# with HTTPS_MODE=off when certbot fails, so the site keeps working on HTTP.
addr_compute_url() {
  local url_host="${HOST}"
  if addr_valid_ipv6 "${HOST}"; then
    url_host="[${HOST}]"
  fi
  case "${HTTPS_MODE}" in
    letsencrypt|proxy)
      APP_URL="https://${url_host}"
      SESSION_SECURE="true"
      ;;
    off)
      APP_URL="http://${url_host}"
      [[ "${HTTP_PORT}" != "80" ]] && APP_URL="${APP_URL}:${HTTP_PORT}"
      # "auto" upgrades the cookie by itself if HTTPS is added in front later.
      SESSION_SECURE="auto"
      ;;
  esac
  if [[ "${HTTPS_MODE}" == "proxy" ]]; then
    TRUST_PROXY="true"
  else
    TRUST_PROXY="false"
  fi
}

addr_summary() {
  echo "    address mode : ${ADDRESS_MODE}"
  echo "    host         : ${HOST}"
  echo "    HTTP port    : ${HTTP_PORT}"
  echo "    HTTPS        : ${HTTPS_MODE}"
  echo "    APP_URL      : ${APP_URL}"
}

# addr_set_env FILE KEY VALUE - replaces or appends KEY=VALUE.
addr_set_env() {
  local file="$1" key="$2" value="$3"
  local escaped="${value//\\/\\\\}"
  escaped="${escaped//|/\\|}"
  escaped="${escaped//&/\\&}"
  if grep -qE "^${key}=" "${file}"; then
    sed -i "s|^${key}=.*|${key}=${escaped}|" "${file}"
  else
    printf '%s=%s\n' "${key}" "${value}" >>"${file}"
  fi
}

addr_write_env() {
  local file="$1"
  addr_set_env "${file}" APP_URL "${APP_URL}"
  addr_set_env "${file}" SESSION_SECURE "${SESSION_SECURE}"
  addr_set_env "${file}" TRUST_PROXY "${TRUST_PROXY}"
  addr_set_env "${file}" SITE_ADDRESS_MODE "${ADDRESS_MODE}"
  addr_set_env "${file}" SITE_HTTPS_MODE "${HTTPS_MODE}"
  addr_set_env "${file}" SITE_HTTP_PORT "${HTTP_PORT}"
}

# Writes /etc/nginx/sites-available/mcpanel.conf from the template and
# disables any vhost an earlier run of the installer created.
addr_install_vhost() {
  local app_dir="$1" php_sock="$2" admin_path="$3"
  local available="/etc/nginx/sites-available/${ADDR_VHOST_NAME}"
  local enabled="/etc/nginx/sites-enabled/${ADDR_VHOST_NAME}"
  local server_name="${HOST}" listen_flags=""

  # Older installers named the vhost after the domain; drop those links.
  local link target
  for link in /etc/nginx/sites-enabled/*; do
    [[ -e "${link}" || -L "${link}" ]] || continue
    [[ "${link}" == "${enabled}" ]] && continue
    target="$(readlink -f "${link}" 2>/dev/null || echo "${link}")"
    # Ours if it carries the marker, or (older installers) serves this app.
    if [[ -f "${target}" ]] && { grep -qF "${ADDR_VHOST_MARKER#\# }" "${target}" \
        || { grep -qF "root ${app_dir}/public;" "${target}" && grep -qF "Minecraft Hosting Platform" "${target}"; }; }; then
      echo "    disabling previous vhost ${link}"
      rm -f "${link}"
    fi
  done

  if [[ "${ADDRESS_MODE}" == "ip" ]]; then
    # Requests by IP carry the IP in the Host header; nginx cannot list an
    # IPv6 literal in server_name, so IPv6 relies on default_server.
    addr_valid_ipv6 "${HOST}" && server_name="_"
    if ! grep -rqsE "listen[^;]*[: ]${HTTP_PORT}[^0-9;][^;]*default_server" /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null; then
      listen_flags=" default_server"
    elif [[ "${server_name}" == "_" ]]; then
      echo "!! another Nginx site is already default_server on port ${HTTP_PORT}: requests to [${HOST}] may reach it instead." >&2
      echo "   Use a free port, e.g. HTTP_PORT=8080." >&2
    fi
  fi

  install -m 0644 "${app_dir}/deploy/nginx/mcpanel.conf" "${available}"
  sed -i \
    -e "s|__SERVER_NAME__|${server_name}|g" \
    -e "s|__HTTP_PORT__|${HTTP_PORT}|g" \
    -e "s|__LISTEN_FLAGS__|${listen_flags}|g" \
    -e "s|__APP_DIR__|${app_dir}|g" \
    -e "s|__PHP_FPM_SOCK__|${php_sock}|g" \
    -e "s|__ADMIN_PATH__|${admin_path}|g" \
    "${available}"
  if ! addr_ipv6_enabled; then
    # Kernel without IPv6: "listen [::]" would make nginx refuse to start.
    sed -i '/listen \[::\]:/d' "${available}"
  fi
  ln -sfn "${available}" "${enabled}"
  rm -f /etc/nginx/sites-enabled/default
  if ! nginx -t; then
    rm -f "${enabled}"
    addr_die "the generated Nginx configuration is invalid (see above); ${available} kept for inspection"
  fi
  systemctl reload nginx
}

addr_ipv6_enabled() {
  [[ -f /proc/net/if_inet6 ]] && [[ "$(cat /proc/sys/net/ipv6/conf/all/disable_ipv6 2>/dev/null || echo 0)" != "1" ]]
}

addr_open_firewall() {
  command -v ufw >/dev/null 2>&1 || return 0
  ufw status 2>/dev/null | grep -q "Status: active" || return 0
  ufw allow "${HTTP_PORT}/tcp" >/dev/null
  [[ "${HTTPS_MODE}" == "letsencrypt" ]] && ufw allow 443/tcp >/dev/null
  echo "    ufw: opened ${HTTP_PORT}/tcp$([[ "${HTTPS_MODE}" == "letsencrypt" ]] && echo ' and 443/tcp')"
}

# Requests the certificate. Returns 1 (and leaves the site on HTTP) on failure.
addr_request_certificate() {
  [[ "${HTTPS_MODE}" == "letsencrypt" ]] || return 0
  echo "==> Requesting the Let's Encrypt certificate for ${DOMAIN}"
  apt-get install -y certbot python3-certbot-nginx >/dev/null
  if certbot --nginx -d "${DOMAIN}" -m "${LETSENCRYPT_EMAIL}" --agree-tos --redirect -n; then
    return 0
  fi
  echo "!! certbot failed (DNS not pointing here yet, or port 80 closed)." >&2
  echo "   The site stays on http://${DOMAIN} until you run:" >&2
  echo "     sudo DOMAIN=${DOMAIN} LETSENCRYPT_EMAIL=${LETSENCRYPT_EMAIL} bash deploy/set-address.sh" >&2
  return 1
}

# URL that reaches this server's own Nginx for the healthcheck, whatever DNS
# or load balancer sits in front. Prints "URL|curl option|option value"
# (option and value empty when none is needed).
addr_local_health() {
  local mode="${SITE_ADDRESS_MODE:-}" https="${SITE_HTTPS_MODE:-}" port="${SITE_HTTP_PORT:-80}"
  local app_url="${APP_URL:-}"
  local authority="${app_url#*://}"
  authority="${authority%%/*}"
  local host="${authority}"
  if [[ "${host}" == \[* ]]; then
    host="${host#[}"
    host="${host%%]*}"
  else
    host="${host%%:*}"
  fi
  if [[ -z "${mode}" ]]; then
    # Installed before SITE_* existed: plain APP_URL, as the old script did.
    printf '%s||' "${app_url%/}/api/v1/health"
  elif [[ "${https}" == "letsencrypt" ]]; then
    printf '%s|--resolve|%s:443:127.0.0.1' "https://${host}/api/v1/health" "${host}"
  else
    printf '%s|--header|Host: %s' "http://127.0.0.1:${port}/api/v1/health" "${authority}"
  fi
}
