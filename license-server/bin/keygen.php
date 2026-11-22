<?php

declare(strict_types=1);

/**
 * Generates the Ed25519 signing keypair.
 *
 *   php bin/keygen.php [--force]
 *
 * The private key never leaves this host (0600, outside the web root). Paste
 * the printed LICENSE_PUBLIC_KEY into every customer panel's .env. Rotating the
 * key means shipping a panel release that trusts the new key: keep the old
 * public key accepted during the overlap if you need zero downtime.
 */

$dir = dirname(__DIR__) . '/keys';
$privatePath = $dir . '/private.key';
$publicPath = $dir . '/public.key';
$force = in_array('--force', $argv, true);

if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}
if ((is_file($privatePath) || is_file($publicPath)) && !$force) {
    fwrite(STDERR, "Keys already exist. Use --force only to rotate (all issued licences stay signed with the old key).\n");
    exit(1);
}

$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);

if (file_put_contents($privatePath, base64_encode($secret)) === false) {
    fwrite(STDERR, "Cannot write private key\n");
    exit(1);
}
chmod($privatePath, 0600);
if (file_put_contents($publicPath, base64_encode($public)) === false) {
    fwrite(STDERR, "Cannot write public key\n");
    exit(1);
}
chmod($publicPath, 0644);

fwrite(STDOUT, "Private key: {$privatePath} (0600)\n");
fwrite(STDOUT, "Public key:  {$publicPath}\n\n");
fwrite(STDOUT, "Add this line to every panel .env:\nLICENSE_PUBLIC_KEY=" . base64_encode($public) . "\n");
