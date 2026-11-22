<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpClient;
use App\Core\Json;
use App\Core\Logger;

/**
 * Licence client. Every response from the licence server is signed with Ed25519
 * and verified with the public key shipped in the release, so a compromised or
 * spoofed endpoint cannot unlock the panel.
 *
 * If the licence server is unreachable the panel keeps working inside the
 * configured grace period and existing customer servers are never touched.
 */
final class LicenseClient
{
    /** @var array<string,mixed>|null */
    private static ?array $state = null;

    /** @return array<string,mixed> */
    public static function state(): array
    {
        if (self::$state !== null) {
            return self::$state;
        }
        try {
            self::$state = App::db()->selectOne('SELECT * FROM license_state ORDER BY id ASC LIMIT 1') ?? [];
        } catch (\Throwable) {
            self::$state = [];
        }

        return self::$state;
    }

    public static function key(): string
    {
        $state = self::state();
        if (!empty($state['license_key_enc'])) {
            try {
                return (string) Crypto::decrypt((string) $state['license_key_enc']);
            } catch (\Throwable) {
                return '';
            }
        }

        return (string) Config::get('app.license_key', '');
    }

    /** @return array<string,mixed> */
    public static function activate(string $key, ?string $serverUrl = null): array
    {
        $server = rtrim($serverUrl ?: self::serverUrl(), '/');
        if ($server === '') {
            throw new \RuntimeException(__('license_server_missing'));
        }
        $response = (new HttpClient($server, [], 15, 2, 'license'))->post('/api/v1/activate', [
            'license' => trim($key),
            'fingerprint' => App::fingerprint(),
            // Domain or IP address, IPv6 without the URL brackets.
            'domain' => app_address((string) Config::get('app.url', ''))['host'],
            'version' => App::version(),
        ]);
        $json = $response->json();
        if (!$response->isOk()) {
            $code = (string) ($json['error'] ?? '');
            throw new \RuntimeException($code !== '' ? self::explain($code) : __('license_activation_failed') . ' (HTTP ' . $response->status . ')');
        }
        $payload = self::verify($json);
        self::store(trim($key), $payload);
        if ($serverUrl !== null && $serverUrl !== '') {
            // Remember a server typed in the admin, so periodic checks use it.
            App::setSetting('license_server', $server);
        }

        return $payload;
    }

    /** Licence server URL: LICENSE_SERVER in .env, otherwise the one saved from the admin. */
    public static function serverUrl(): string
    {
        $server = (string) Config::get('app.license_server', '');
        if ($server === '') {
            $server = (string) App::setting('license_server', '');
        }
        if ($server === '' && @is_readable(self::LOCAL_SERVER_DIR . '/keys/public.key')) {
            $server = self::LOCAL_SERVER_URL;
        }

        return rtrim($server, '/');
    }

    /** Default location of a licence server installed on this same machine. */
    public const LOCAL_SERVER_DIR = '/var/www/license-server';
    public const LOCAL_SERVER_URL = 'http://127.0.0.1:8090';

    /**
     * Licence server public key, in order: LICENSE_PUBLIC_KEY in .env, the key
     * saved from the admin, the key of a licence server on this machine.
     */
    public static function publicKey(): string
    {
        foreach ([(string) Config::get('app.license_public_key', ''), (string) App::setting('license_public_key', '')] as $candidate) {
            if (self::validPublicKey($candidate)) {
                return trim($candidate);
            }
        }
        $local = self::LOCAL_SERVER_DIR . '/keys/public.key';
        if (@is_readable($local)) {
            $candidate = trim((string) @file_get_contents($local));
            if (self::validPublicKey($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    public static function validPublicKey(string $value): bool
    {
        $raw = base64_decode(trim($value), true);

        return $raw !== false && strlen($raw) === 32;
    }

    public static function hasPublicKey(): bool
    {
        return self::publicKey() !== '';
    }

    /** Readable message for an error code returned by the licence server. */
    public static function explain(string $error): string
    {
        $known = ['invalid_license', 'license_expired', 'license_suspended', 'license_revoked', 'fingerprint_mismatch',
            'domain_mismatch', 'activation_limit_reached', 'rate_limited', 'unavailable', 'missing_fields'];
        if (in_array($error, $known, true)) {
            return __('license_error_' . $error);
        }
        if (stripos($error, 'connect') !== false || stripos($error, 'resolve') !== false || stripos($error, 'timed out') !== false || stripos($error, 'timeout') !== false) {
            return __('license_error_unreachable', ['detail' => $error]);
        }

        return $error;
    }

    /** @return array<string,mixed> */
    public static function check(bool $force = false): array
    {
        $state = self::state();
        if (!$force && !empty($state['last_check_at']) && strtotime((string) $state['last_check_at'] . ' UTC') > time() - 3600) {
            return $state;
        }
        $key = self::key();
        if ($key === '') {
            return ['status' => 'unlicensed'];
        }
        $server = self::serverUrl();
        if ($server === '') {
            return self::degrade($state, 'licence server not configured');
        }
        try {
            $response = (new HttpClient(rtrim($server, '/'), [], 15, 2, 'license'))->post('/api/v1/check', [
                'license' => $key,
                'fingerprint' => App::fingerprint(),
                'version' => App::version(),
            ]);
            $json = $response->json();
            if (!$response->isOk()) {
                $error = (string) ($json['error'] ?? 'check failed');
                // A clear answer (revoked, expired, unknown key, other machine)
                // ends the licence now; only outages get the grace period.
                if (in_array($response->status, [403, 404, 409], true)) {
                    return self::reject($error === 'license_expired' ? 'expired' : 'invalid', $error);
                }

                return self::degrade($state, $error);
            }
            $payload = self::verify($json);
            self::store($key, $payload);

            return $payload;
        } catch (\Throwable $e) {
            Logger::warning('license.check_failed', ['error' => $e->getMessage()]);

            return self::degrade($state, $e->getMessage());
        }
    }

    /** Test hook: forces a status without a database or licence server. */
    private static ?string $forcedStatus = null;

    public static function fake(?string $status): void
    {
        self::$forcedStatus = $status;
        self::$state = null;
        self::$status = null;
    }

    /**
     * Effective status: active, lifetime, grace, expired, invalid or unlicensed.
     * A stored "active" licence whose expiry date has passed counts as expired
     * even if the licence server has not been reached since.
     */
    public static function status(): string
    {
        if (self::$forcedStatus !== null) {
            return self::$forcedStatus;
        }
        if (self::$status !== null) {
            return self::$status;
        }

        return self::$status = self::computeStatus();
    }

    private static ?string $status = null;

    private static function computeStatus(): string
    {
        $state = self::state();
        if (self::key() === '' || $state === []) {
            return 'unlicensed';
        }
        $status = (string) ($state['status'] ?? 'unlicensed');
        $expires = $state['expires_at'] ?? null;
        if ($status === 'active' && $expires !== null && strtotime((string) $expires . ' UTC') < time()) {
            return 'expired';
        }
        if ($status === 'grace') {
            $lastValid = !empty($state['last_valid_at']) ? strtotime((string) $state['last_valid_at'] . ' UTC') : 0;
            $graceDays = max(0, (int) Config::get('app.license_grace_days', 7));
            if ($lastValid <= 0 || time() - $lastValid >= $graceDays * 86400) {
                return 'invalid';
            }
        }

        return $status;
    }

    /** True with an active, lifetime or in-grace licence. */
    public static function isValid(): bool
    {
        return in_array(self::status(), ['active', 'grace', 'lifetime'], true);
    }

    /** New orders, cart additions and checkout need a valid licence. */
    public static function allowsSales(): bool
    {
        return self::isValid();
    }

    /** Appearance, products and add-ons can be changed only with a valid licence. */
    public static function allowsCustomization(): bool
    {
        return self::isValid();
    }

    public static function daysLeft(): ?int
    {
        $expires = self::state()['expires_at'] ?? null;
        if ($expires === null) {
            return null;
        }

        return (int) ceil((strtotime((string) $expires . ' UTC') - time()) / 86400);
    }

    /** @return array{level:string,message:string}|null */
    public static function banner(): ?array
    {
        $status = self::status();
        if ($status === 'active' || $status === 'lifetime') {
            return null;
        }
        if ($status === 'grace') {
            return ['level' => 'warning', 'message' => __('license_grace_notice', ['days' => (int) Config::get('app.license_grace_days', 7)])];
        }
        if ($status === 'unlicensed') {
            return ['level' => 'danger', 'message' => __('license_unlicensed_notice')];
        }

        return ['level' => 'danger', 'message' => __('license_invalid_notice')];
    }

    /** @return array<string,mixed> */
    private static function verify(array $json): array
    {
        $payload = (array) ($json['payload'] ?? []);
        $signature = base64_decode((string) ($json['signature'] ?? ''), true);
        $publicKey = base64_decode(self::publicKey(), true);
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('The PHP sodium extension is required for licence verification');
        }
        if ($signature === false || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException(__('license_public_key_missing'));
        }
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false || !sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)) {
            throw new \RuntimeException(__('license_signature_invalid'));
        }
        if (!empty($payload['fingerprint']) && !hash_equals((string) $payload['fingerprint'], App::fingerprint())) {
            throw new \RuntimeException(__('license_fingerprint_mismatch'));
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private static function store(string $key, array $payload): void
    {
        $db = App::db();
        $data = [
            'license_key_enc' => Crypto::encrypt($key),
            'plan' => (string) ($payload['plan'] ?? 'standard'),
            'fingerprint' => (string) ($payload['fingerprint'] ?? App::fingerprint()),
            'status' => (string) ($payload['status'] ?? 'active'),
            'expires_at' => isset($payload['expires_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $payload['expires_at'])) : null,
            'last_check_at' => Database::now(),
            'last_valid_at' => Database::now(),
            'payload_json' => Json::encode($payload),
        ];
        $existing = $db->selectOne('SELECT id FROM license_state ORDER BY id ASC LIMIT 1');
        if ($existing === null) {
            $db->insert('license_state', $data);
        } else {
            $db->update('license_state', $data, 'id = :id', ['id' => (int) $existing['id']]);
        }
        self::$state = array_merge(self::state(), $data);
        self::$status = null;
    }

    /** @return array<string,mixed> */
    private static function reject(string $status, string $reason): array
    {
        $db = App::db();
        $existing = $db->selectOne('SELECT id FROM license_state ORDER BY id ASC LIMIT 1');
        if ($existing !== null) {
            $db->update('license_state', ['status' => $status, 'last_check_at' => Database::now()], 'id = :id', ['id' => (int) $existing['id']]);
        }
        self::$state = null;
        self::$status = null;
        Logger::warning('license.rejected', ['status' => $status, 'reason' => $reason]);

        return ['status' => $status, 'error' => $reason];
    }

    /** @param array<string,mixed> $state */
    private static function degrade(array $state, string $reason): array
    {
        $lastValid = !empty($state['last_valid_at']) ? strtotime((string) $state['last_valid_at'] . ' UTC') : 0;
        $graceDays = max(0, (int) Config::get('app.license_grace_days', 7));
        $status = $lastValid > 0 && time() - $lastValid < $graceDays * 86400 ? 'grace' : 'invalid';
        $db = App::db();
        $existing = $db->selectOne('SELECT id FROM license_state ORDER BY id ASC LIMIT 1');
        if ($existing !== null) {
            $db->update('license_state', ['status' => $status], 'id = :id', ['id' => (int) $existing['id']]);
        }
        self::$state = null;
        self::$status = null;
        Logger::warning('license.degraded', ['status' => $status, 'reason' => $reason]);

        return ['status' => $status, 'error' => $reason];
    }
}
