<?php

declare(strict_types=1);

use App\Core\Env;

$base = dirname(__DIR__);
$sqlitePath = (string) Env::get('DB_SQLITE_PATH', 'storage/database.sqlite');
if (!str_starts_with($sqlitePath, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $sqlitePath)) {
    $sqlitePath = $base . '/' . ltrim($sqlitePath, '/');
}

return [
    'app' => [
        'name' => (string) Env::get('APP_NAME', 'Minecraft Hosting'),
        'env' => (string) Env::get('APP_ENV', 'production'),
        'debug' => (bool) Env::get('APP_DEBUG', false),
        'url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/'),
        'key' => (string) Env::get('APP_KEY', ''),
        'blind_index_key' => (string) Env::get('BLIND_INDEX_KEY', ''),
        'currency' => (string) Env::get('APP_CURRENCY', 'EUR'),
        'locale' => (string) Env::get('APP_LOCALE', 'en'),
        'locales' => ['en', 'it'],
        'timezone' => (string) Env::get('APP_TIMEZONE', 'Europe/Rome'),
        'version' => '1.2.0',
        'license_key' => (string) Env::get('LICENSE_KEY', ''),
        'license_public_key' => (string) Env::get('LICENSE_PUBLIC_KEY', ''),
        'license_server' => rtrim((string) Env::get('LICENSE_SERVER', ''), '/'),
        'license_grace_days' => (int) Env::get('LICENSE_GRACE_DAYS', 7),
        'telemetry' => (bool) Env::get('TELEMETRY_OPT_IN', false),
    ],
    'database' => [
        'driver' => (string) Env::get('DB_DRIVER', 'mysql'),
        'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', 3306),
        'database' => (string) Env::get('DB_DATABASE', 'mchosting'),
        'sqlite_path' => $sqlitePath,
        'username' => (string) Env::get('DB_USERNAME', 'mchosting'),
        'password' => (string) Env::get('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => (string) Env::get('SESSION_NAME', 'mch_session'),
        // true | false | auto. "auto" marks the cookie Secure only when the
        // request arrived over HTTPS: needed for installations reached by IP
        // over plain HTTP, where a Secure cookie would never be sent back.
        'secure' => session_cookie_secure(Env::get('SESSION_SECURE', 'auto')),
        'lifetime' => 7200,
        'samesite' => 'Lax',
    ],
    'security' => [
        'admin_path' => trim((string) Env::get('ADMIN_PATH', 'admin'), '/'),
        'admin_ip_allowlist' => (string) Env::get('ADMIN_IP_ALLOWLIST', ''),
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'reset_token_ttl' => 3600,
        'verify_token_ttl' => 86400,
        'captcha' => [
            'site_key' => (string) Env::get('TURNSTILE_SITE_KEY', ''),
            'secret' => (string) Env::get('TURNSTILE_SECRET', ''),
        ],
    ],
    'pterodactyl' => [
        'url' => rtrim((string) Env::get('PTERODACTYL_URL', ''), '/'),
        'application_key' => (string) Env::get('PTERODACTYL_APP_KEY', ''),
        'client_key' => (string) Env::get('PTERODACTYL_CLIENT_KEY', ''),
        'timeout' => (int) Env::get('PTERODACTYL_TIMEOUT', 20),
        'retries' => (int) Env::get('PTERODACTYL_RETRIES', 3),
        'version' => (string) Env::get('PTERODACTYL_VERSION', ''),
        'node_strategy' => 'least_allocated', // least_allocated | round_robin | location
    ],
    'payments' => [
        'stripe' => [
            'secret' => (string) Env::get('STRIPE_SECRET', ''),
            'webhook_secret' => (string) Env::get('STRIPE_WEBHOOK_SECRET', ''),
            'mode' => (string) Env::get('STRIPE_MODE', 'test'),
        ],
        'paypal' => [
            'client_id' => (string) Env::get('PAYPAL_CLIENT_ID', ''),
            'client_secret' => (string) Env::get('PAYPAL_CLIENT_SECRET', ''),
            'mode' => (string) Env::get('PAYPAL_MODE', 'sandbox'),
        ],
        'bank' => [
            'iban' => (string) Env::get('BANK_IBAN', ''),
            'holder' => (string) Env::get('BANK_HOLDER', ''),
            'bic' => (string) Env::get('BANK_BIC', ''),
        ],
    ],
    'mail' => [
        'driver' => (string) Env::get('MAIL_DRIVER', 'log'),
        'host' => (string) Env::get('MAIL_HOST', '127.0.0.1'),
        'port' => (int) Env::get('MAIL_PORT', 587),
        'username' => (string) Env::get('MAIL_USERNAME', ''),
        'password' => (string) Env::get('MAIL_PASSWORD', ''),
        'encryption' => (string) Env::get('MAIL_ENCRYPTION', 'tls'),
        'from_address' => (string) Env::get('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'from_name' => (string) Env::get('MAIL_FROM_NAME', (string) Env::get('APP_NAME', 'Minecraft Hosting')),
    ],
    'paths' => [
        'base' => $base,
        'storage' => $base . '/storage',
        'views' => $base . '/resources/views',
        'lang' => $base . '/lang',
    ],
];
