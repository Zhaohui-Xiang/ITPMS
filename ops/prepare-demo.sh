#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 2 || ! "$2" =~ ^https?://[a-zA-Z0-9.:-]+$ ]]; then
  echo "Usage: prepare-demo.sh SOURCE_DIRECTORY APP_URL" >&2
  exit 2
fi
[[ $(id -u) -eq 0 ]] || { echo "Root is required for one-time provisioning." >&2; exit 2; }
source_dir=$(realpath "$1")
app_url=$2
[[ -f "$source_dir/ipms-backend/artisan" && -f "$source_dir/ipms-frontend/dist/index.html" ]]
base=/var/www/ipms
install -d -o itpms -g itpms -m 0755 "$base/releases"
install -d -o itpms -g itpms -m 0700 "$base/shared"
if [[ ! -f "$base/shared/.env" ]]; then
  db_password=$(openssl rand -hex 24)
  demo_password="Demo-$(openssl rand -hex 8)"
  app_key="base64:$(openssl rand -base64 32)"
  umask 077
  {
    printf 'APP_NAME=IPMS\nAPP_ENV=demo\nAPP_DEBUG=false\nAPP_KEY=%s\nAPP_URL=%s\nAPP_LOCALE=zh_CN\n' "$app_key" "$app_url"
    printf 'DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=itpms_demo\nDB_USERNAME=itpms_demo\nDB_PASSWORD=%s\n' "$db_password"
    printf 'SESSION_DRIVER=file\nSESSION_DOMAIN=null\nSESSION_SECURE_COOKIE=false\nSESSION_SAME_SITE=lax\n'
    printf 'SANCTUM_STATEFUL_DOMAINS=%s,127.0.0.1:8082,localhost:8082\n' "${app_url#*://}"
    printf 'CACHE_STORE=file\nQUEUE_CONNECTION=redis\nREDIS_CLIENT=predis\nREDIS_HOST=127.0.0.1\nREDIS_DB=4\nREDIS_CACHE_DB=5\nREDIS_PREFIX=itpms_demo_\nMAIL_MAILER=array\n'
    printf 'IPMS_SEED_DEMO=true\nIPMS_DEMO_PASSWORD=%s\n' "$demo_password"
  } > "$base/shared/.env"
  chown itpms:itpms "$base/shared/.env"
  chmod 600 "$base/shared/.env"
  # Secrets are passed through stdin, not process arguments.
  printf "CREATE ROLE itpms_demo LOGIN PASSWORD '%s';\nCREATE DATABASE itpms_demo OWNER itpms_demo;\n" "$db_password" |
    runuser -u postgres -- psql --set ON_ERROR_STOP=1 >/dev/null
  unset db_password demo_password app_key
fi
release_id="demo-$(date -u +%Y%m%d%H%M%S)"
release="$base/releases/$release_id"
install -d -o itpms -g itpms -m 0755 "$release"
cp -a "$source_dir/ipms-backend" "$release/backend"
cp -a "$source_dir/ipms-frontend/dist" "$release/frontend"
if [[ ! -d "$base/shared/storage" ]]; then
  cp -a "$release/backend/storage" "$base/shared/storage"
fi
rm -rf "$release/backend/storage"
ln -s "$base/shared/storage" "$release/backend/storage"
ln -s "$base/shared/.env" "$release/backend/.env"
install -d -o itpms -g itpms -m 0700 "$base/shared/storage"/{framework/sessions,framework/views,framework/cache/data,app/private,app/public,logs}
chown -R itpms:itpms "$release" "$base/shared/storage"
chmod 700 "$base/shared"
runuser -u itpms -- bash -euc 'cd "$1"; php artisan config:clear; php artisan migrate --force; php "$2/ops/seed-demo.php" "$1"; php artisan config:cache; php artisan route:cache; if [[ -d resources/views ]]; then php artisan view:cache; fi' -- "$release/backend" "$source_dir"
chmod 700 "$release/backend/bootstrap/cache"
find "$release/backend/bootstrap/cache" -type f -exec chmod 600 {} +
ln -sfn "$release" "$base/candidate"
# A dedicated pool keeps application storage owned by the deployment user.
{
  printf '[itpms]\nuser = itpms\ngroup = itpms\nlisten = /run/php/itpms-fpm.sock\nlisten.owner = www-data\nlisten.group = www-data\nlisten.mode = 0660\npm = ondemand\npm.max_children = 8\npm.process_idle_timeout = 10s\n'
  printf 'php_admin_value[upload_max_filesize] = 20M\nphp_admin_value[post_max_size] = 24M\n'
} > /etc/php/8.2/fpm/pool.d/itpms.conf
systemctl reload php8.2-fpm
cp "$source_dir/ops/nginx-candidate.conf" /etc/nginx/sites-enabled/itpms-candidate
nginx -t
systemctl reload nginx
curl --fail --silent --retry 10 --retry-connrefused --retry-delay 1 http://127.0.0.1:8082/up >/dev/null
printf 'Candidate ready: %s\n' "$release_id"
