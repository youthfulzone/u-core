@echo off
echo Starting Laravel Queue Worker...
cd "C:\Users\TheOldBuffet\Herd\u-core"

:loop
echo [%date% %time%] Starting queue worker
C:\Users\TheOldBuffet\.config\herd\bin\php83.bat artisan queue:work --sleep=3 --tries=1 --max-time=3600
echo [%date% %time%] Queue worker stopped, restarting in 5 seconds...
timeout /t 5 /nobreak > nul
goto loop