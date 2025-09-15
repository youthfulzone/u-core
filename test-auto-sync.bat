@echo off
REM Test the automatic e-Factura sync manually

echo ===============================================
echo 🧪 TESTING E-FACTURA AUTO SYNC
echo ===============================================
echo.
echo This will test the automatic sync that runs at 3 AM.
echo.
echo Choose an option:
echo 1. Generate report only (no syncing)
echo 2. Run full sync test with 7 days
echo 3. Run full sync test with 30 days
echo 4. Cancel
echo.
set /p choice="Enter your choice (1-4): "

if "%choice%"=="1" (
    echo.
    echo 📊 Generating report only...
    echo.
    C:\Users\TheOldBuffet\.config\herd\bin\php83.bat artisan efactura:auto-sync --report-only --days=60
) else if "%choice%"=="2" (
    echo.
    echo 🔄 Running sync test with 7 days...
    echo.
    C:\Users\TheOldBuffet\.config\herd\bin\php83.bat artisan efactura:auto-sync --days=7
) else if "%choice%"=="3" (
    echo.
    echo 🔄 Running sync test with 30 days...
    echo.
    C:\Users\TheOldBuffet\.config\herd\bin\php83.bat artisan efactura:auto-sync --days=30
) else (
    echo.
    echo ❌ Cancelled or invalid choice.
    goto end
)

echo.
echo ===============================================
echo ✅ Test completed!
echo ===============================================

:end
echo.
echo Press any key to close...
pause >nul