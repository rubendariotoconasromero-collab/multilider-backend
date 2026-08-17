@echo off
cd /d C:\laragon\www\multilider\backend
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan schedule:run >> storage\logs\schedule.log 2>&1
