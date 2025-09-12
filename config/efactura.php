<?php

return [

    /*
    |--------------------------------------------------------------------------
    | E-Factura ANAF OAuth Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for ANAF's e-Factura OAuth 2.0 authentication system.
    | These credentials are required for accessing the ANAF e-Factura API.
    |
    */

    'client_id' => env('EFACTURA_CLIENT_ID'),
    'client_secret' => env('EFACTURA_CLIENT_SECRET'),
    'redirect_uri' => env('EFACTURA_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | ANAF OAuth Endpoints
    |--------------------------------------------------------------------------
    |
    | OAuth endpoints for ANAF's e-Factura system. These are the official
    | endpoints provided by ANAF for authentication and token management.
    |
    */

    'oauth' => [
        'authorization_url' => 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize',
        'token_url' => 'https://logincert.anaf.ro/anaf-oauth2/v1/token',
        'scope' => 'read write',
    ],

    /*
    |--------------------------------------------------------------------------
    | E-Factura API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the ANAF e-Factura API endpoints and settings.
    |
    */

    'api' => [
        'base_url' => 'https://api.anaf.ro/prod/FCTEL/rest',
        'timeout' => 30,
        'retry_attempts' => 3,
        'retry_delay' => 1000, // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security settings for token management. These settings control how
    | tokens are stored, refreshed, and managed according to ANAF requirements.
    |
    */

    'token' => [
        'validity_days' => 90,
        'refresh_token_validity_days' => 365,
        'minimum_refresh_interval_days' => 90,
        'cleanup_expired_after_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security and Audit Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for security monitoring and audit trail management.
    |
    */

    'security' => [
        'enable_audit_logging' => true,
        'track_usage_statistics' => true,
        'alert_on_expiry_days' => [30, 7, 1],
        'max_concurrent_tokens' => 1,
    ],

];