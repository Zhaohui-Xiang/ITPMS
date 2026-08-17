#!/bin/bash
set -e
: "${IPMS_ADMIN_USERNAME:?Set IPMS_ADMIN_USERNAME before running this script}"
: "${IPMS_ADMIN_PASSWORD:?Set IPMS_ADMIN_PASSWORD before running this script}"
echo "===== IPMS Fix & Restart ====="

cd /var/www/ipms/backend

# 1. Fix permissions
chmod -R 775 bootstrap/cache storage
chown -R www-data:www-data bootstrap/cache storage

# 2. Kill old server
fuser -k 8000/tcp 2>/dev/null || true
fuser -k 8000/tcp 2>/dev/null || true

# 3. Clear cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# 4. Restart
nohup php artisan serve --host=127.0.0.1 --port=8000 > /dev/null 2>&1 &

sleep 3

# 5. Test login
echo ""
echo "===== Testing Login API ====="
python3 << 'PYEOF'
import json, os, urllib.request, urllib.error
url = "http://127.0.0.1:8000/api/login"
data = json.dumps({
    "username": os.environ["IPMS_ADMIN_USERNAME"],
    "password": os.environ["IPMS_ADMIN_PASSWORD"],
}).encode()
req = urllib.request.Request(url, data=data, headers={"Content-Type":"application/json","Accept":"application/json"})
try:
    resp = urllib.request.urlopen(req)
    print("STATUS:", resp.status)
    print("BODY:", resp.read().decode()[:500])
except urllib.error.HTTPError as e:
    print("HTTP ERROR:", e.code, e.read().decode()[:500])
except Exception as e:
    print("ERROR:", e)
PYEOF

# 6. Test other endpoints
echo ""
echo "===== Testing Dashboard API ====="
python3 << 'PYEOF3'
import urllib.request, urllib.error
url = "http://127.0.0.1:8000/api/dashboard/stats"
req = urllib.request.Request(url, headers={"Accept":"application/json"})
try:
    resp = urllib.request.urlopen(req)
    print("STATUS:", resp.status, "(expected 401 for unauthenticated)")
except Exception as e:
    print("ERROR:", e)
PYEOF3

# 7. Reload Nginx
echo ""
echo "===== Reloading Nginx ====="
nginx -t && systemctl reload nginx

# 8. Check frontend
echo ""
echo "===== Frontend Check ====="
ls -la /var/www/ipms/frontend/dist/index.html 2>/dev/null && echo "dist/index.html OK" || echo "MISSING: dist/index.html"

echo ""
echo "===== Fix Complete ====="
echo "URL: http://116.62.44.192"
