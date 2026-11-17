@echo off
cd /d "%~dp0"
echo Preparing a NEW Monetka installation for HandyHost.
echo Do not run this on a project with existing data.
if exist .env goto exists
if exist database\database.sqlite goto exists
where php >nul 2>&1 || goto missing
where composer >nul 2>&1 || goto missing
where npm >nul 2>&1 || goto missing
php -r "if (PHP_VERSION_ID < 80401) {fwrite(STDERR, 'PHP 8.4.1 or newer is required.' . PHP_EOL); exit(1);} if (!extension_loaded('pdo_sqlite')) {fwrite(STDERR, 'PHP pdo_sqlite is missing.' . PHP_EOL); exit(1);}"
if errorlevel 1 goto fail
call composer install --no-dev --prefer-dist --optimize-autoloader
if errorlevel 1 goto fail
copy .env.example .env >nul
if errorlevel 1 goto fail
php -r "touch('database/database.sqlite');"
if errorlevel 1 goto fail
php artisan key:generate
if errorlevel 1 goto fail
php artisan migrate --force
if errorlevel 1 goto fail
call npm install
if errorlevel 1 goto fail
call npm run build
if errorlevel 1 goto fail
echo.
echo Done. Follow README.html to upload the project.
pause
exit /b 0
:exists
echo .env or database\database.sqlite already exists. Stopped to protect existing data.
pause
exit /b 1
:missing
echo PHP, Composer or npm was not found. Install Laravel Herd and Node.js LTS, then reopen this window.
pause
exit /b 1
:fail
echo.
echo Preparation failed. Do not upload yet. Send the error text or screenshot for help.
echo Partial files may have been created. Do not delete them if they contain your data.
pause
exit /b 1
