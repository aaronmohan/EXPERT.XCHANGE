@echo off
echo Creating recovery configuration...
cd /d "D:\xampp1\mysql\bin"

echo [mysqld] > my_recovery.ini
echo innodb_force_recovery=4 >> my_recovery.ini
echo datadir=D:/xampp1/mysql/data >> my_recovery.ini
echo port=3306 >> my_recovery.ini
echo socket=MySQL >> my_recovery.ini

echo Stopping MySQL service if running...
net stop MySQL
timeout /t 5

echo Starting MySQL with recovery mode...
start /b mysqld --defaults-file=my_recovery.ini

echo Waiting for MySQL to start...
timeout /t 10

echo Creating dump of all databases...
mysqldump -u root --all-databases > "D:\xampp1\mysql\all_databases_backup.sql"

echo Stopping recovery instance...
taskkill /F /IM mysqld.exe

echo Removing recovery configuration...
del my_recovery.ini

echo Recovery completed! Your databases have been backed up to all_databases_backup.sql
pause 