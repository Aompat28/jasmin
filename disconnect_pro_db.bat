@echo off
setlocal
chcp 65001 >nul
title Disconnect Production Database

echo ========================================================
echo   Disconnecting Production Database Tunnel...
echo ========================================================

taskkill /fi "WINDOWTITLE eq PRO_DB_TUNNEL*" /f /t >nul 2>&1
powershell -NoProfile -Command "Get-Process ssh -ErrorAction SilentlyContinue | Stop-Process -Force" >nul 2>&1

echo.
echo [SUCCESS] Disconnected from Production Database.
echo Port 3307 is now closed.
ping -n 3 127.0.0.1 >nul
