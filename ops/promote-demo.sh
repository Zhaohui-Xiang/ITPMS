#!/usr/bin/env bash
set -euo pipefail
[[ $(id -u) -eq 0 && $# -eq 1 ]] || { echo "Usage (root): promote-demo.sh SOURCE_DIRECTORY" >&2; exit 2; }
source_dir=$(realpath "$1")
base=/var/www/ipms
release=$(readlink -f "$base/candidate")
[[ "$release" == "$base/releases/demo-"* && -f "$release/backend/artisan" && -f "$release/frontend/index.html" ]]
[[ -f "$source_dir/ops/nginx-demo.conf" ]]
curl --fail --silent --retry 5 --retry-connrefused http://127.0.0.1:8082/up >/dev/null
curl --fail --silent http://127.0.0.1:8082/sanctum/csrf-cookie >/dev/null
previous=$(readlink -f "$base/current" || true)
backup=$(mktemp -d /var/backups/itpms-demo.XXXXXXXX)
cp -a /etc/nginx/sites-available/ipms "$backup/nginx.conf"
rollback() {
    trap - ERR
    cp -a "$backup/nginx.conf" /etc/nginx/sites-available/ipms
    if [[ -n "$previous" && -d "$previous" ]]; then
        ln -sfn "$previous" "$base/.current-rollback"
        mv -Tf "$base/.current-rollback" "$base/current"
    fi
    nginx -t && systemctl reload nginx
    systemctl reload php8.2-fpm
    echo "Promotion failed; previous Nginx configuration restored." >&2
    exit 1
}
trap rollback ERR
ln -sfn "$release" "$base/.current-next"
mv -Tf "$base/.current-next" "$base/current"
cp "$source_dir/ops/nginx-demo.conf" /etc/nginx/sites-available/ipms
nginx -t
systemctl reload php8.2-fpm
systemctl reload nginx
curl --fail --silent --retry 5 --retry-connrefused http://127.0.0.1/up >/dev/null
curl --fail --silent --retry 10 --retry-connrefused --retry-delay 1 http://127.0.0.1/sanctum/csrf-cookie >/dev/null
curl --fail --silent http://127.0.0.1/ | grep -q '<div id="app">'
trap - ERR
printf 'Demo promoted: %s; configuration backup: %s\n' "$(basename "$release")" "$backup"
