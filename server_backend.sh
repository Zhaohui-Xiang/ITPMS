#!/bin/bash
set -e
: "${IPMS_DB_PASSWORD:?Set IPMS_DB_PASSWORD before running this script}"
cd /var/www/ipms/backend

echo "===== 安装 Composer 依赖 ====="
composer install --no-dev --optimize-autoloader -q

echo "===== 配置环境变量 ====="
cp .env.example .env
sed -i "s/DB_HOST=.*/DB_HOST=127.0.0.1/" .env
sed -i "s/DB_PORT=.*/DB_PORT=5432/" .env
sed -i "s/DB_DATABASE=.*/DB_DATABASE=ipms/" .env
sed -i "s/DB_USERNAME=.*/DB_USERNAME=ipms/" .env
sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=${IPMS_DB_PASSWORD}/" .env
sed -i "s/SANCTUM_STATEFUL_DOMAINS=.*/SANCTUM_STATEFUL_DOMAINS=116.62.44.192,localhost/" .env
sed -i "s|APP_URL=.*|APP_URL=http://116.62.44.192|" .env
sed -i "s/SESSION_DRIVER=.*/SESSION_DRIVER=redis/" .env

echo "===== 生成应用密钥 ====="
php artisan key:generate

echo "===== 运行数据库迁移 ====="
php artisan migrate --force

echo "===== 运行数据填充 ====="
php artisan db:seed --force

echo "===== 创建存储软链接 ====="
php artisan storage:link

echo "===== 设置目录权限 ====="
chown -R www-data:www-data /var/www/ipms/backend/storage
chown -R www-data:www-data /var/www/ipms/backend/bootstrap/cache
chmod -R 775 /var/www/ipms/backend/storage
chmod -R 775 /var/www/ipms/backend/bootstrap/cache

echo "===== 后端配置完成 ====="
