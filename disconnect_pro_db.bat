@echo off
chcp 65001 >nul
title Disconnect Production Database
echo ========================================================
echo   Disconnecting Production Database Tunnel...
echo ========================================================

powershell -NoProfile -Command "Get-NetTCPConnection -LocalPort 3307 -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }" >nul 2>&1
powershell -NoProfile -Command "Get-Process ssh -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match '3307' -or $_.MainWindowTitle -match 'PRO_DB_TUNNEL' } | Stop-Process -Force -ErrorAction SilentlyContinue" >nul 2>&1
taskkill /fi "WINDOWTITLE eq PRO_DB_TUNNEL*" /f /t >nul 2>&1

echo.
echo [SUCCESS] Disconnected from Production Database.
echo Port 3307 is now closed.
echo.
timeout /t 3
exit /b 0
