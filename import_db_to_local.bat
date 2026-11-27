@echo off
chcp 65001 >nul
title Import Database to Localhost
echo ========================================================
echo   Importing Database to Localhost (property_management)
echo ========================================================
echo.

if not exist "%~dp0database_latest.sql" (
    echo [ERROR] Not found: database_latest.sql
    pause
    exit /b 1
)

set MYSQL_BIN=C:\xampp\mysql\bin\mysql.exe
if not exist "%MYSQL_BIN%" set MYSQL_BIN=mysql

echo Importing database_latest.sql...
"%MYSQL_BIN%" -u root property_management < "%~dp0database_latest.sql"

if %errorlevel% equ 0 (
    echo.
    echo ========================================================
    echo   [SUCCESS] Imported database to Localhost successfully!
    echo   All tenant accounts and latest data are ready to use.
    echo ========================================================
) else (
    echo.
    echo [ERROR] Failed to import. Please make sure MySQL in XAMPP is running.
)

echo.
pause
exit /b 0
