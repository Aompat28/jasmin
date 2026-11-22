@echo off
title Disconnect Production Database
echo Disconnecting Production Database Tunnel...
taskkill /fi "WINDOWTITLE eq PRO_DB_TUNNEL*" /f >nul 2>&1
powershell -Command "Get-Process ssh -ErrorAction SilentlyContinue | Stop-Process -Force" >nul 2>&1
echo Disconnected successfully.
ping -n 3 127.0.0.1 >nul
