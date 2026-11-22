<?php

declare(strict_types=1);

/**
 *   php bin/revoke.php <license-key> [revoked|suspended|active]
 *
 * Revoked licences stop activating immediately and fail the hourly check. The
 * panel keeps serving existing customers inside the configured grace period:
 * revocation is for new sales and renewals, never a kill switch for servers.
 */

require dirname(__DIR__) . '/src/Store.php';

use LicenseServer\Store;

$config = require dirname(__DIR__) . '/config.php';

$key = strtoupper(trim((string) ($argv[1] ?? '')));
$status = in_array(($argv[2] ?? 'revoked'), ['revoked', 'suspended', 'active'], true) ? $argv[2] : 'revoked';

if ($key === '') {
    fwrite(STDERR, "Usage: php bin/revoke.php <license-key> [revoked|suspended|active]\n");
    exit(1);
}

$store = new Store((string) $config['db_path']);
$license = $store->findByKey($key);
if ($license === null) {
    fwrite(STDERR, "License not found.\n");
    exit(2);
}
$store->setStatus((int) $license['id'], $status);
fwrite(STDOUT, "License {$key} set to {$status}.\n");
