<?php
echo "PHP Version: " . PHP_VERSION . "\n";
echo "MongoDB Extension: " . (extension_loaded('mongodb') ? 'Installed' : 'Not Installed') . "\n";

if (!extension_loaded('mongodb')) {
    echo "\n=== MongoDB PHP Extension Installation Required ===\n";
    echo "To use MongoDB with Laravel, you need to install the MongoDB PHP extension.\n\n";
    
    echo "For Windows with Laravel Herd:\n";
    echo "1. Download the MongoDB PHP driver from: https://pecl.php.net/package/mongodb\n";
    echo "2. Choose the correct version for PHP " . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . " (Thread Safe x64)\n";
    echo "3. Extract php_mongodb.dll to your PHP extensions directory\n";
    echo "4. Add 'extension=mongodb' to your php.ini file\n";
    echo "5. Restart Laravel Herd\n\n";
    
    echo "Current PHP configuration:\n";
    echo "Loaded php.ini: " . php_ini_loaded_file() . "\n";
    echo "Extensions directory: " . ini_get('extension_dir') . "\n";
}