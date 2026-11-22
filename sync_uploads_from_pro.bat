@echo off
setlocal
echo ========================================================
echo   Syncing Uploads from Production to Local...
echo ========================================================

set SSH_ASKPASS=%USERPROFILE%\bin\askpass.bat
set SSH_ASKPASS_REQUIRE=force

echo [1/4] Backing up local uploads folder...
if not exist "%USERPROFILE%\bin" mkdir "%USERPROFILE%\bin"
tar -czf "%USERPROFILE%\bin\local_uploads_backup.tar.gz" -C "C:\xampp\htdocs\jasmin" uploads

echo [2/4] Packing uploads on Production Server...
ssh -o StrictHostKeyChecking=no fuyuki@ssh.sourceped.com "tar -czf /home/fuyuki/pro_uploads.tar.gz -C /var/www/property_management uploads"
if %errorlevel% neq 0 (
    echo [ERROR] Failed to pack on remote server.
    exit /b 1
)

echo [3/4] Downloading uploads package...
scp -o StrictHostKeyChecking=no fuyuki@ssh.sourceped.com:/home/fuyuki/pro_uploads.tar.gz "%USERPROFILE%\bin\pro_uploads.tar.gz"
if %errorlevel% neq 0 (
    echo [ERROR] Download failed.
    exit /b 1
)

echo [4/4] Extracting to Local uploads folder...
tar -xzf "%USERPROFILE%\bin\pro_uploads.tar.gz" -C "C:\xampp\htdocs\jasmin"

echo.
echo ========================================================
echo   Sync Uploads SUCCESS!
echo ========================================================
echo.
