<?php

declare(strict_types=1);

/**
 * Issues a licence key.
 *
 *   php bin/issue.php <plan> <days|0> [max_activations] [domain] [notes]
 *
 * Examples:
 *   php bin/issue.php standard 365 1
 *   php bin/issue.php enterprise 0 3 "panel.example.com" "Lifetime, 3 panels"
 *
 * days = 0 means lifetime (no expiry). The key is printed once: store it in the
 * customer record of your billing system, it is not recoverable from the
 * licence server after creation (only the hash would be, but we keep it to
 * support activation lookups).
 */

require dirname(__DIR__) . '/src/Store.php';

use LicenseServer\Store;

$config = require dirname(__DIR__) . '/config.php';

if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php bin/issue.php <plan> <days|0> [max_activations] [domain] [notes]\n");
    exit(1);
}

$plan = preg_replace('/[^a-z0-9_-]/', '', strtolower($argv[1])) ?: 'standard';
$days = max(0, (int) $argv[2]);
$max = max(1, (int) ($argv[3] ?? 1));
$domain = isset($argv[4]) && $argv[4] !== '-' ? strtolower(trim($argv[4])) : null;
$notes = $argv[5] ?? null;

$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$key = 'MCH';
for ($block = 0; $block < 4; $block++) {
    $key .= '-';
    for ($i = 0; $i < 4; $i++) {
        $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}

$store = new Store((string) $config['db_path']);
$store->create([
    'license_key' => $key,
    'plan' => $plan,
    'status' => 'active',
    'max_activations' => $max,
    'domain' => $domain,
    'expires_at' => $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null,
    'notes' => $notes,
]);

fwrite(STDOUT, $key . PHP_EOL);
fwrite(STDOUT, sprintf(
    "plan=%s days=%s max_activations=%d domain=%s\n",
    $plan,
    $days > 0 ? (string) $days : 'lifetime',
    $max,
    $domain ?? 'any'
));
