# MongoDB CLI Solution for Laravel Herd

## Problem Solved
MongoDB extension was not loading in CLI commands despite working in the Laravel application through the web server.

## Root Cause
1. PHP version mismatch - CLI was using PHP 8.4 while the project was configured for PHP 8.3
2. The MongoDB extension was installed for PHP 8.3 but not properly configured for CLI usage
3. Laravel Herd was isolating the site to use PHP 8.3 for web requests but CLI was defaulting to PHP 8.4

## Solution

### 1. Site Isolation
The site has been isolated to use PHP 8.3:
```bash
# This was done via Herd MCP tool
mcp__herd__isolate_or_unisolate_site with phpVersion: 8.3
```

### 2. Using Correct PHP Version in CLI
Always use the Herd-specific PHP 8.3 binary for CLI commands:
```bash
# Instead of:
php artisan command

# Use:
C:/Users/TheOldBuffet/.config/herd/bin/php83.bat artisan command
```

### 3. MongoDB Extension Configuration
The MongoDB extension is properly configured in:
- Location: `C:\Users\TheOldBuffet\.config\herd\bin\php83\php.ini`
- Extension: `extension=php_mongodb.dll`
- Extension directory: `extension_dir = "ext"`

## Verified Working Commands

### Check MongoDB Extension
```bash
C:/Users/TheOldBuffet/.config/herd/bin/php83.bat -m | grep -i mongo
# Output: mongodb
```

### Run Artisan Commands
```bash
C:/Users/TheOldBuffet/.config/herd/bin/php83.bat artisan --version
# Output: Laravel Framework 12.24.0
```

### Test MongoDB Connection via Tinker
```php
// In tinker or via mcp__laravel-boost__tinker
$client = new MongoDB\Client("mongodb://127.0.0.1:27017");
$databases = $client->listDatabases();
// Successfully lists: admin, admin_database, asociatie, config, local
```

## Quick Reference for Future Use

### For any CLI MongoDB operations:
1. Always prefix commands with: `C:/Users/TheOldBuffet/.config/herd/bin/php83.bat`
2. Or create an alias in your shell profile for convenience
3. The Laravel Boost MCP tools (like tinker) automatically use the correct PHP version

### MongoDB is accessible at:
- Host: 127.0.0.1
- Port: 27017
- Database: admin_database (as configured in .env)

## Permanent Fix Applied
The site is now isolated to PHP 8.3, ensuring consistency between web and CLI environments.