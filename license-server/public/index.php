<?php

declare(strict_types=1);

/**
 * Licence server HTTP entrypoint (separate deployment, PHP 8.5, no framework).
 *
 *   POST /api/v1/activate  {license, fingerprint, domain, version}
 *   POST /api/v1/check     {license, fingerprint, version}
 *   GET  /api/v1/health
 *
 * Every successful response is {payload: {...}, signature: base64(Ed25519)}.
 * The panel verifies the signature with the public key compiled into the
 * release, so this server cannot be impersonated even with a valid TLS cert.
 */

require dirname(__DIR__) . '/src/Store.php';

use LicenseServer\Store;

$config = require dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function logLine(string $message, array $context = []): void
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    @file_put_contents(
        $dir . '/license.log',
        json_encode(['ts' => gmdate('c'), 'message' => $message, 'context' => $context], JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

/** @return array<string,mixed> */
function payloadFor(array $license, string $fingerprint, ?string $domain): array
{
    $expires = $license['expires_at'] ?? null;
    $status = (string) $license['status'];
    if ($expires !== null && strtotime((string) $expires . ' UTC') < time() && $status === 'active') {
        $status = 'expired';
    }

    return [
        'license_id' => (int) $license['id'],
        'plan' => (string) $license['plan'],
        'status' => $status,
        'fingerprint' => $fingerprint,
        'domain' => $domain,
        'expires_at' => $expires !== null ? gmdate('c', strtotime((string) $expires . ' UTC')) : null,
        'issued_at' => gmdate('c'),
        'product' => 'minecraft-hosting-platform',
    ];
}

function sign(array $payload, string $privateKeyPath): string
{
    if (!is_readable($privateKeyPath)) {
        throw new RuntimeException('private key missing');
    }
    $secret = file_get_contents($privateKeyPath);
    $secret = $secret === false ? '' : trim($secret);
    $decoded = base64_decode($secret, true);
    if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        $secret = $decoded;
    }
    if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('private key must be 64 raw bytes or base64 of them');
    }
    $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($canonical === false) {
        throw new RuntimeException('cannot encode payload');
    }

    return base64_encode(sodium_crypto_sign_detached($canonical, $secret));
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = '/' . trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

if ($method === 'GET' && $path === '/api/v1/health') {
    respond(200, ['ok' => true, 'time' => gmdate('c')]);
}
if ($method !== 'POST' || !in_array($path, ['/api/v1/activate', '/api/v1/check'], true)) {
    respond(404, ['error' => 'not_found']);
}
if (!str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    respond(415, ['error' => 'json_required']);
}

try {
    $store = new Store((string) $config['db_path']);
} catch (Throwable $e) {
    logLine('db_error', ['error' => $e->getMessage()]);
    respond(503, ['error' => 'unavailable']);
}

if (!$store->allow($ip)) {
    respond(429, ['error' => 'rate_limited']);
}

$raw = file_get_contents('php://input', false, null, 0, 8192);
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    respond(400, ['error' => 'invalid_json']);
}

$key = trim((string) ($body['license'] ?? ''));
$fingerprint = trim((string) ($body['fingerprint'] ?? ''));
$domain = isset($body['domain']) ? mb_substr(trim((string) $body['domain']), 0, 190) : null;
$version = isset($body['version']) ? mb_substr(trim((string) $body['version']), 0, 40) : null;

if ($key === '' || $fingerprint === '' || strlen($fingerprint) > 128) {
    respond(422, ['error' => 'missing_fields']);
}

try {
    $license = $store->findByKey($key);
    if ($license === null) {
        usleep(120000); // constant-ish time, no oracle
        respond(404, ['error' => 'invalid_license']);
    }
    if ((string) $license['status'] !== 'active') {
        respond(403, ['error' => 'license_' . $license['status']]);
    }
    if (!empty($license['expires_at']) && strtotime((string) $license['expires_at'] . ' UTC') < time()) {
        $store->setStatus((int) $license['id'], 'expired');
        respond(403, ['error' => 'license_expired']);
    }
    if (!empty($license['domain']) && $domain !== null && !hash_equals(strtolower((string) $license['domain']), strtolower($domain))) {
        respond(409, ['error' => 'domain_mismatch']);
    }

    $activation = $store->activation((int) $license['id'], $fingerprint);
    if ($activation === null && $store->activationCount((int) $license['id']) >= (int) $license['max_activations']) {
        respond(409, ['error' => 'activation_limit_reached']);
    }
    $store->touchActivation((int) $license['id'], $fingerprint, $domain, $version);
    $store->markChecked((int) $license['id']);

    $payload = payloadFor($license, $fingerprint, $domain);
    if ($payload['status'] === 'expired') {
        respond(403, ['error' => 'license_expired']);
    }
    respond(200, ['payload' => $payload, 'signature' => sign($payload, (string) $config['private_key_path'])]);
} catch (Throwable $e) {
    logLine('server_error', ['error' => $e->getMessage(), 'path' => $path]);
    respond(500, ['error' => 'server_error']);
}
