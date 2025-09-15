@echo off
REM Setup Windows Task Scheduler for e-Factura auto sync

echo Setting up Windows Task Scheduler for e-Factura automatic sync...

REM Delete existing task if it exists
schtasks /delete /tn "E-Factura Auto Sync" /f >nul 2>&1

REM Create new scheduled task for 3:00 AM daily
schtasks /create ^
    /tn "E-Factura Auto Sync" ^
    /tr "C:\Users\TheOldBuffet\Herd\u-core\schedule-efactura-sync.bat automated" ^
    /sc daily ^
    /st 03:00 ^
    /ru "SYSTEM" ^
    /rl highest ^
    /f

if %ERRORLEVEL% EQU 0 (
    echo ✅ Successfully created Windows scheduled task: "E-Factura Auto Sync"
    echo 📅 Task will run daily at 3:00 AM Romania time
    echo 📂 Logs will be saved to: storage\logs\efactura-auto-sync.log
    echo.
    echo To view the task:
    echo   schtasks /query /tn "E-Factura Auto Sync"
    echo.
    echo To run manually now:
    echo   schtasks /run /tn "E-Factura Auto Sync"
    echo.
    echo To delete the task:
    echo   schtasks /delete /tn "E-Factura Auto Sync" /f
) else (
    echo ❌ Failed to create scheduled task. Please run as Administrator.
)

pause