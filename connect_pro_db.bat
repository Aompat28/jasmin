@echo off
chcp 65001 >nul
title Connect to Production Database
echo ========================================================
echo   Connecting to Production Database (SSH Tunnel)...
echo ========================================================
echo.

netstat -ano | findstr ":3307" | findstr "LISTENING" >nul
if %errorlevel% equ 0 goto :already_running

echo [1/2] Starting SSH Tunnel to Production on port 3307...
start "PRO_DB_TUNNEL" /min ssh -n -N -L 3307:127.0.0.1:3306 -o ServerAliveInterval=15 fuyuki@ssh.sourceped.com

echo [2/2] Waiting for SSH Tunnel to establish (3-5 sec)...
set /a count=0
:wait_loop
timeout /t 1 /nobreak >nul
netstat -ano | findstr ":3307" | findstr "LISTENING" >nul
if %errorlevel% equ 0 goto :connected
set /a count+=1
if %count% geq 15 goto :timeout_error
goto :wait_loop

:already_running
echo [OK] SSH Tunnel is already running on port 3307!
goto :open_browser

:connected
echo [OK] Tunnel established successfully!

:open_browser
echo Opening phpMyAdmin for Production Database...
start http://localhost/phpmyadmin/index.php?server=2

echo.
echo ========================================================
echo   SUCCESS! Connected to Production Database.
echo   - Host: 127.0.0.1
echo   - Port: 3307
echo   - Database: property_management
echo   - Server: Production Server (Live 3307)
echo ========================================================
echo.
echo   * You can close this window anytime; tunnel will stay active.
echo   * To disconnect when done, run: disconnect_pro_db.bat
echo.
pause
exit /b 0

:timeout_error
echo.
echo [ERROR] Could not connect to Production Database tunnel within 15 seconds.
echo Please check your internet connection or Cloudflare access.
pause
exit /b 1
