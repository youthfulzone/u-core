@echo off
REM E-Factura Automatic Sync Scheduler
REM Runs daily at 3:00 AM Romania time

echo [%date% %time%] Starting e-Factura automatic sync...

REM Change to project directory
cd /d "C:\Users\TheOldBuffet\Herd\u-core"

REM Run the auto sync command with proper PHP version
C:\Users\TheOldBuffet\.config\herd\bin\php83.bat artisan efactura:auto-sync --days=60

REM Log completion
echo [%date% %time%] e-Factura sync completed with exit code: %ERRORLEVEL%

REM Keep window open for 10 seconds if run manually
if "%1" NEQ "automated" (
    timeout /t 10
)