#!/bin/bash
set -e
cd /var/www/ipms/frontend

echo "===== 安装 npm 依赖 ====="
npm install

echo "===== 构建生产版本 ====="
npm run build

echo "===== 前端构建完成 ====="
