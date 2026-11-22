<?php

declare(strict_types=1);

/**
 * Licence server configuration. Deploy this app on its own host, separate from
 * the customer panels, so a compromised panel cannot issue licences.
 *
 * Environment (or a .env file loaded by the web server):
 *   LICENSE_DB_PATH        SQLite file, default storage/licenses.sqlite
 *   LICENSE_PRIVATE_KEY    Ed25519 secret key (raw 64 bytes), default keys/private.key
 *   LICENSE_PUBLIC_KEY     Ed25519 public key (raw 32 bytes), default keys/public.key
 *   LICENSE_GRACE_DAYS     default 7 (informational, panels enforce it)
 */
return [
    'db_path' => getenv('LICENSE_DB_PATH') ?: __DIR__ . '/storage/licenses.sqlite',
    'private_key_path' => getenv('LICENSE_PRIVATE_KEY') ?: __DIR__ . '/keys/private.key',
    'public_key_path' => getenv('LICENSE_PUBLIC_KEY') ?: __DIR__ . '/keys/public.key',
    'grace_days' => (int) (getenv('LICENSE_GRACE_DAYS') ?: 7),
];
