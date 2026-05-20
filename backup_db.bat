@echo off
echo Creating backup of current MySQL data...
cd /d "D:\xampp1\mysql"
if exist "data_backup_%date:~-4,4%%date:~-7,2%%date:~-10,2%" (
    echo Backup folder already exists
) else (
    mkdir "data_backup_%date:~-4,4%%date:~-7,2%%date:~-10,2%"
    xcopy /E /I "data\*.*" "data_backup_%date:~-4,4%%date:~-7,2%%date:~-10,2%\"
)
echo Backup completed!
pause 