<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ANAF SPV Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for ANAF Spatiul Privat Virtual (SPV) integration.
    | This includes certificate authentication settings for automated access.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Certificate Authentication
    |--------------------------------------------------------------------------
    |
    | ANAF requires digital certificate authentication for automated access.
    | Configure the path to your PKCS#12 certificate file and password.
    |
    | To obtain a certificate:
    | 1. Visit ANAF and request a digital certificate
    | 2. Download the certificate file (usually .p12 format)
    | 3. Place it in storage/certificates/ directory
    | 4. Set the path and password in your .env file
    |
    */

    'certificate_path' => env('ANAF_CERTIFICATE_PATH', storage_path('certificates/anaf.p12')),
    'certificate_password' => env('ANAF_CERTIFICATE_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | PKCS#11 Token Authentication (Alternative)
    |--------------------------------------------------------------------------
    |
    | For tokens with non-exportable private keys, use PKCS#11 integration.
    | This method accesses the token directly without exporting private keys.
    |
    */

    'pkcs11_library_path' => env('ANAF_PKCS11_LIBRARY_PATH', 'C:\\Windows\\System32\\eps2003csp11.dll'),
    'token_pin' => env('ANAF_TOKEN_PIN', ''),
    'certificate_label' => env('ANAF_CERTIFICATE_LABEL', ''),

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | ANAF SPV web services configuration
    |
    */

    'api_base_url' => 'https://webserviced.anaf.ro/SPVWS2/rest',
    'api_timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | SSL Configuration
    |--------------------------------------------------------------------------
    |
    | SSL settings for ANAF API communication.
    | Note: ANAF may require specific SSL configurations.
    |
    */

    'ssl_verify' => env('ANAF_SSL_VERIFY', false),
    'ssl_verify_peer' => env('ANAF_SSL_VERIFY_PEER', false),
    'ssl_verify_peer_name' => env('ANAF_SSL_VERIFY_PEER_NAME', false),

    /*
    |--------------------------------------------------------------------------
    | Browser Automation Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for Selenium-based browser automation (alternative method)
    | for tokens that don't support certificate export.
    |
    */

    'chrome_driver_path' => env('ANAF_CHROME_DRIVER_PATH', 'C:\\chromedriver\\chromedriver.exe'),
    'chrome_path' => env('ANAF_CHROME_PATH', 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'),
    'automation_timeout' => env('ANAF_AUTOMATION_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Session and response caching settings
    |
    */

    'cache_timeout' => 3 * 60 * 60, // 3 hours
    'cache_prefix' => 'anaf_spv_',
];