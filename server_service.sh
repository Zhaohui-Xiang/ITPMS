#!/bin/bash
set -e

echo "===== 配置 Nginx ====="
cat > /etc/nginx/sites-available/ipms << 'NGINXEOF'
server {
    listen 80;
    server_name 116.62.44.192;

    root /var/www/ipms/frontend/dist;
    index index.html;

    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /sanctum {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location /storage {
        alias /var/www/ipms/backend/storage/app/public;
    }
}
NGINXEOF

ln -sf /etc/nginx/sites-available/ipms /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "===== 配置 PHP-FPM ====="
sed -i "s/pm =.*/pm = ondemand/" /etc/php/8.2/fpm/pool.d/www.conf
sed -i "s/pm.max_children =.*/pm.max_children = 20/" /etc/php/8.2/fpm/pool.d/www.conf
sed -i "s/pm.start_servers =.*/pm.start_servers = 2/" /etc/php/8.2/fpm/pool.d/www.conf
systemctl restart php8.2-fpm

echo "===== 配置定时任务 ====="
echo '* * * * * cd /var/www/ipms/backend && php artisan schedule:run >> /dev/null 2>&1' | crontab -

echo "===== 配置 Queue Worker ====="
apt-get install -y -qq supervisor
cat > /etc/supervisor/conf.d/ipms-worker.conf << 'SVEOF'
[program:ipms-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ipms/backend/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/ipms/backend/storage/logs/worker.log
stopwaitsecs=3600
SVEOF

supervisorctl reread
supervisorctl update
supervisorctl start ipms-worker:*

echo "===== 启动应用服务 ====="
cd /var/www/ipms/backend
nohup php artisan serve --host=127.0.0.1 --port=8000 > /dev/null 2>&1 &

echo "========================================="
echo "  IPMS 部署完成！"
echo "  访问地址: http://116.62.44.192"
echo "========================================="
