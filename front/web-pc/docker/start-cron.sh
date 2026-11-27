#!/bin/sh
# Supervisor starts cron through this wrapper so cron jobs can read Docker/K8s env vars.
set -eu

tmp=/etc/fg-docker-env.tmp
: > "$tmp"
printenv | grep -E '^(APP_ENV|DB_)=' | sed 's/^/export /' >> "$tmp" || true
if [ -r /proc/1/environ ]; then
  tr '\0' '\n' < /proc/1/environ | grep -E '^(APP_ENV|DB_)=' | sed 's/^/export /' >> "$tmp" || true
fi

awk -F= '
{
  line = $0
  key = $1
  sub(/^export /, "", key)
  last[key] = line
}
END {
  for (k in last) print last[k]
}
' "$tmp" > /etc/fg-docker-env
rm -f "$tmp"
chmod 644 /etc/fg-docker-env

mkdir -p /var/www/html/runtime/log
chown -R www-data:www-data /var/www/html/runtime/log || true

exec /usr/sbin/cron -f
