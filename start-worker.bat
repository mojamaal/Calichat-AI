@echo off
REM Keeps the CaliChat queue worker running (restarts hourly or after a crash).
REM Stop it: create the file storage\worker.stop, then close this window.
cd /d %~dp0
:loop
if exist storage\worker.stop (del storage\worker.stop & exit /b)
C:\xampp\php\php.exe cli\worker.php
timeout /t 2 /nobreak >nul
goto loop
