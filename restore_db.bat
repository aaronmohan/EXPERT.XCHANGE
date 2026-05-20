@echo off
echo Stopping MySQL service if running...
net stop MySQL
timeout /t 5

echo Initializing fresh MySQL data directory...
cd /d "D:\xampp1\mysql"
if exist "data_old" rd /s /q "data_old"
move "data" "data_old"
mkdir "data"

echo Copying fresh MySQL files...
xcopy /E /I "backup\*.*" "data\"

echo Restoring database from backup...
cd /d "D:\xampp1\mysql\bin"
start /b mysqld --standalone
timeout /t 10

mysql -u root < "D:\xampp1\mysql\all_databases_backup.sql"

echo Stopping temporary MySQL instance...
taskkill /F /IM mysqld.exe
timeout /t 5

echo Starting MySQL service...
net start MySQL

echo Restore completed! Please check if MySQL starts in XAMPP Control Panel.
pause 