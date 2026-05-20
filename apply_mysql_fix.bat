@echo off
echo ===== MySQL Fix Script =====
echo.

REM Check for Administrator privileges
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo This script requires Administrator privileges.
    echo Please right-click and select "Run as Administrator"
    pause
    exit /b 1
)

echo Stopping XAMPP services...
net stop MySQL >nul 2>&1
net stop Apache2.4 >nul 2>&1
timeout /t 5

echo.
echo Backing up original MySQL configuration...
cd /d "D:\xampp1\mysql\bin"
if exist "my.ini.bak" del "my.ini.bak"
if exist "my.ini" (
    rename "my.ini" "my.ini.bak"
    if errorlevel 1 (
        echo Failed to backup my.ini
        pause
        exit /b 1
    )
)

echo.
echo Copying new configuration...
copy "%~dp0mysql_fix.ini" "my.ini"
if errorlevel 1 (
    echo Failed to copy new configuration
    pause
    exit /b 1
)

echo.
echo Resetting MySQL data directory permissions...
icacls "D:\xampp1\mysql\data" /grant Everyone:(OI)(CI)F
if errorlevel 1 (
    echo Failed to set permissions
    pause
    exit /b 1
)

echo.
echo Killing any existing MySQL processes...
taskkill /F /IM mysqld.exe >nul 2>&1

echo.
echo Starting MySQL service...
net start MySQL
if errorlevel 1 (
    echo Failed to start MySQL service
) else (
    echo MySQL service started successfully
)

echo.
echo Script completed! Please check if MySQL starts in XAMPP Control Panel.
echo If it doesn't start, please share any error messages you see.
echo.
pause 