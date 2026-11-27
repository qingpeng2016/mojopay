#!/bin/bash
# Called by cron as root to load Docker/K8s DB env vars. Run PHP as the web user
# so CLI cache files remain writable by PHP-FPM.
set -euo pipefail
cd /var/www/html

load_from_environ_file() {
  local f="$1"
  [[ -r "$f" ]] || return 1
  while IFS= read -r -d '' item; do
    case "$item" in
      APP_ENV=*|DB_*)
        export "$item"
        ;;
    esac
  done < "$f"
  return 0
}

load_from_environ_file /proc/1/environ || true

if [[ -f /etc/fg-docker-env ]]; then
  set -a
  # shellcheck disable=SC1091
  source /etc/fg-docker-env
  set +a
fi

if [[ -z "${DB_HOST:-}" ]]; then
  while IFS= read -r line; do
    case "$line" in
      APP_ENV=*|DB_*)
        export "$line"
        ;;
    esac
  done < <(printenv | grep -E '^(APP_ENV|DB_)=' || true)
fi

if [[ -z "${DB_HOST:-}" ]]; then
  echo "[$(date -u +'%Y-%m-%d %H:%M:%S') UTC] ERROR: DB_HOST empty after loading env; skip weekly-statement" >&2
  echo "  whoami=$(whoami) proc1_readable=$([[ -r /proc/1/environ ]] && echo yes || echo no) fg_env=$([[ -f /etc/fg-docker-env ]] && echo yes || echo no)" >&2
  if [[ -f /etc/fg-docker-env ]]; then
    echo "  /etc/fg-docker-env keys: $(grep -E '^(export )?(APP_ENV|DB_)' /etc/fg-docker-env | sed 's/=.*//' | tr '\n' ' ')" >&2
  fi
  if [[ -r /proc/1/environ ]]; then
    echo "  proc1 DB/APP keys: $(tr '\0' '\n' < /proc/1/environ | grep -E '^(APP_ENV|DB_)' | sed 's/=.*//' | tr '\n' ' ')" >&2
  fi
  exit 1
fi

exec /usr/bin/setpriv --reuid=www-data --regid=www-data --init-groups \
  /usr/local/bin/php think bitfinex:weekly-statement
