@echo off
title Connect to Production Database
echo ========================================================
echo   Connecting to Production Database (SSH Tunnel)...
echo ========================================================

netstat -ano | findstr :3307 >nul
if %errorlevel% equ 0 (
    echo [OK] Tunnel is already connected!
) else (
    echo [1/2] Starting SSH Tunnel on port 3307...
    start "PRO_DB_TUNNEL" /min ssh -N -L 3307:127.0.0.1:3306 fuyuki@ssh.sourceped.com
    timeout /t 2 /nobreak >nul
)

echo [2/2] Opening phpMyAdmin (Production)...
start http://localhost/phpmyadmin/index.php?server=2

echo.
echo ========================================================
echo   CONNECTED! phpMyAdmin is now open in your browser.
echo ========================================================
echo.
pause
