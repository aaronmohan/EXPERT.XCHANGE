@echo off
echo Stopping MySQL Service...
net stop MySQL
timeout /t 5

echo Backing up MySQL data...
cd /d "D:\xampp1\mysql\data"
mkdir backup
xcopy /E /I *.* backup\

echo Removing potentially corrupted files...
del ibdata1
del ib_logfile0
del ib_logfile1

echo Copying fresh MySQL files...
copy "D:\xampp1\mysql\backup\ibdata1" .
copy "D:\xampp1\mysql\backup\ib_logfile0" .
copy "D:\xampp1\mysql\backup\ib_logfile1" .

echo Starting MySQL...
net start MySQL

echo Done! Please try starting MySQL in XAMPP Control Panel now.
pause 