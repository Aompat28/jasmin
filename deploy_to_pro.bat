@echo off
setlocal
echo ========================================================
echo   Deploying to Pro Server (Cloudflare Tunnel)...
echo ========================================================

set SSH_ASKPASS=%USERPROFILE%\bin\askpass.bat
set SSH_ASKPASS_REQUIRE=force

echo [1/3] Packing project files...
tar -czf "%USERPROFILE%\bin\deploy_pack.tar.gz" --exclude=".git" --exclude="uploads" --exclude=".env" --exclude="*.sql" --exclude="scratch" -C "C:\xampp\htdocs\jasmin" .

echo [2/3] Uploading to Pro Server...
scp -o StrictHostKeyChecking=no "%USERPROFILE%\bin\deploy_pack.tar.gz" fuyuki@ssh.sourceped.com:~/deploy_pack.tar.gz
if %errorlevel% neq 0 (
    echo [ERROR] Upload failed. Please check connection.
    pause
    exit /b 1
)

echo [3/3] Extracting and updating permissions on Pro Server...
ssh -o StrictHostKeyChecking=no fuyuki@ssh.sourceped.com "/home/fuyuki/do_deploy.sh"

echo.
echo ========================================================
echo   Deploy to Pro SUCCESS!
echo   Website: https://jasmin.sourceped.com
echo ========================================================
echo.
