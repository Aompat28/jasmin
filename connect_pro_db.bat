@echo off
setlocal

if "%~1"=="--daemon" goto :daemon_worker

title Connect to Production Database
echo ========================================================
echo   Connecting to Production Database - SSH Tunnel
echo ========================================================
echo.

netstat -ano | findstr ":3307" | findstr "LISTENING" >nul
if %errorlevel% equ 0 goto :already_listening

echo [1/2] Starting SSH Tunnel to Production on port 3307...
start "PRO_DB_TUNNEL" /min "%~f0" --daemon

echo Waiting for connection...
ping -n 4 127.0.0.1 >nul

:already_listening
echo [2/2] Opening phpMyAdmin for Production Database...
start http://localhost/phpmyadmin/index.php?server=2

echo.
echo ========================================================
echo   CONNECTED TO PRODUCTION DATABASE!
echo   - Host: 127.0.0.1
echo   - Port: 3307
echo   - Database: property_management
echo   - phpMyAdmin URL: http://localhost/phpmyadmin/index.php?server=2
echo ========================================================
echo.
echo   * To disconnect, run: disconnect_pro_db.bat
echo.
pause
exit /b 0

:daemon_worker
title PRO_DB_TUNNEL
set "SSH_ASKPASS=%USERPROFILE%\bin\askpass.bat"
set "SSH_ASKPASS_REQUIRE=force"
:daemon_loop
ssh -n -o StrictHostKeyChecking=no -o ServerAliveInterval=15 -N -L 3307:127.0.0.1:3306 fuyuki@ssh.sourceped.com
ping -n 2 127.0.0.1 >nul
goto daemon_loop
