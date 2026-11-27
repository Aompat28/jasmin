@echo off
chcp 65001 >nul
title Sync Database from Production to Localhost
echo ========================================================
echo   Sync Database from Production to Localhost
echo ========================================================
echo.

python "%~dp0sync_db_from_pro.py"

echo.
pause
exit /b 0
