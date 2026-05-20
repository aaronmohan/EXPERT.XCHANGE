@echo off
echo Stopping all XAMPP services...
net stop MySQL
net stop Apache2.4
timeout /t 5

echo Creating backup of current MySQL data...
cd /d "D:\xampp1\mysql"
if exist "data_old" rd /s /q "data_old"
move "data" "data_old"

echo Installing fresh MySQL data...
xcopy /E /I "backup\*.*" "data\"

echo Copying essential files...
copy "data_old\ibdata1" "data\" 2>nul
copy "data_old\ib_logfile0" "data\" 2>nul
copy "data_old\ib_logfile1" "data\" 2>nul

echo Setting permissions...
icacls "data" /grant Everyone:(OI)(CI)F

echo Done! Please restart XAMPP Control Panel and try starting MySQL.
echo If you need your old databases, they are saved in the data_old folder.
pause 