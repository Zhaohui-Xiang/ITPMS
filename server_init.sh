#!/bin/bash
set -e
: "${IPMS_DB_PASSWORD:?Set IPMS_DB_PASSWORD before running this script}"
echo "===== IPMS 环境初始化 ====="
echo "更新系统包..."
apt-get update -qq
echo "安装基础依赖..."
apt-get install -y -qq curl wget git unzip nginx
echo "安装 PHP 8.2 及扩展..."
apt-get install -y -qq software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -qq
apt-get install -y -qq php8.2 php8.2-fpm php8.2-pgsql php8.2-redis php8.2-curl php8.2-mbstring php8.2-xml php8.2-bcmath php8.2-zip
echo "安装 Composer..."
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
fi
echo "安装 Node.js 20..."
if ! command -v node &> /dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y -qq nodejs
fi
echo "安装 PostgreSQL 15..."
apt-get install -y -qq postgresql postgresql-contrib
echo "安装 Redis..."
apt-get install -y -qq redis-server
echo "创建数据库和用户..."
sudo -u postgres psql --set=db_password="$IPMS_DB_PASSWORD" -c "CREATE USER ipms WITH PASSWORD :'db_password';" 2>/dev/null || echo "用户 ipms 已存在，跳过"
sudo -u postgres psql -c "CREATE DATABASE ipms OWNER ipms;" 2>/dev/null || echo "数据库 ipms 已存在，跳过"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE ipms TO ipms;" 2>/dev/null || true
echo "===== 环境初始化完成 ====="
