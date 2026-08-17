#!/bin/bash
echo "===== 1. Nginx Error Log ====="
tail -30 /var/log/nginx/error.log 2>/dev/null || echo "No nginx error log"

echo ""
echo "===== 2. Laravel Storage Log ====="
tail -50 /var/www/ipms/backend/storage/logs/laravel.log 2>/dev/null || echo "No laravel log"

echo ""
echo "===== 3. PHP-FPM Status ====="
systemctl status php8.2-fpm 2>/dev/null | head -10 || service php8.2-fpm status 2>/dev/null | head -10 || echo "php-fpm not found"

echo ""
echo "===== 4. Nginx Status ====="
systemctl status nginx 2>/dev/null | head -5 || echo "nginx not found"

echo ""
echo "===== 5. Artisan Serve Process ====="
ps aux | grep "artisan serve" | grep -v grep || echo "No artisan serve running"

echo ""
echo "===== 6. PHP Version ====="
php -v 2>/dev/null | head -1 || echo "php not found"

echo ""
echo "===== 7. Composer Installed? ====="
ls /var/www/ipms/backend/vendor/autoload.php 2>/dev/null && echo "Composer OK" || echo "No vendor dir"

echo ""
echo "===== 8. .env file ====="
cat /var/www/ipms/backend/.env 2>/dev/null | grep -E "^(APP_|DB_|SANCTUM)" | head -10 || echo "No .env"

echo ""
echo "===== 9. Storage Permissions ====="
ls -la /var/www/ipms/backend/storage/ 2>/dev/null | head -5 || echo "No storage dir"

echo ""
echo "===== 10. Nginx Config Test ====="
nginx -t 2>&1
