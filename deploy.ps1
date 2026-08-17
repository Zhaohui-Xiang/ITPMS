# IPMS Deploy Script
# Run: .\deploy.ps1

param(
    [Parameter(Mandatory = $true)]
    [string]$Server,
    [Parameter(Mandatory = $true)]
    [string]$User
)

$ErrorActionPreference = "Stop"
$Dir = $PSScriptRoot
$Path = "/var/www/ipms"
$sshTarget = "${User}@${Server}"
$sshOpts = "-o StrictHostKeyChecking=accept-new"

Write-Host "IPMS Deploy - Server: $Server" -ForegroundColor Cyan

# Clean known_hosts entry first (server host key may have changed)
Write-Host "Cleaning known_hosts for $Server..." -ForegroundColor Yellow
ssh-keygen -R $Server 2>$null

# Step 1: Upload Code
Write-Host "[1/3] Uploading code..." -ForegroundColor Yellow

ssh $sshOpts $sshTarget "mkdir -p ${Path}/backend ${Path}/frontend"

scp $sshOpts -r (Join-Path $Dir "ipms-backend\*") "${sshTarget}:${Path}/backend/"
scp $sshOpts -r (Join-Path $Dir "ipms-frontend\src") "${sshTarget}:${Path}/frontend/"
scp $sshOpts (Join-Path $Dir "ipms-frontend\package.json") "${sshTarget}:${Path}/frontend/"
scp $sshOpts (Join-Path $Dir "ipms-frontend\vite.config.js") "${sshTarget}:${Path}/frontend/"
scp $sshOpts (Join-Path $Dir "ipms-frontend\index.html") "${sshTarget}:${Path}/frontend/"

Write-Host "Upload done." -ForegroundColor Green

# Step 2: Deploy scripts
Write-Host "[2/3] Deploying..." -ForegroundColor Yellow

$scripts = @("server_init.sh", "server_backend.sh", "server_frontend.sh", "server_service.sh")
foreach ($s in $scripts) {
    scp $sshOpts (Join-Path $Dir $s) "${sshTarget}:/tmp/"
}

Write-Host "Running init (2-5 min)..." -ForegroundColor Yellow
ssh $sshOpts $sshTarget "bash /tmp/server_init.sh"

Write-Host "Running backend setup..." -ForegroundColor Yellow
ssh $sshOpts $sshTarget "bash /tmp/server_backend.sh"

Write-Host "Building frontend..." -ForegroundColor Yellow
ssh $sshOpts $sshTarget "bash /tmp/server_frontend.sh"

Write-Host "Setting up services..." -ForegroundColor Yellow
ssh $sshOpts $sshTarget "bash /tmp/server_service.sh"

Write-Host "Deploy done." -ForegroundColor Green

# Step 3: Cleanup
Write-Host "[3/3] Cleanup..." -ForegroundColor Yellow
ssh $sshOpts $sshTarget "rm -f /tmp/server_init.sh /tmp/server_backend.sh /tmp/server_frontend.sh /tmp/server_service.sh"
Write-Host "Cleanup done." -ForegroundColor Green

Write-Host "--- IPMS Ready ---" -ForegroundColor Green
Write-Host "URL: http://$Server"
