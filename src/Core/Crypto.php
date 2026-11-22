<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Application-level encryption for personal data (e-mail, addresses, billing
 * details, gateway tokens): AES-256-GCM, authenticated, versioned payloads so
 * keys can be rotated.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $key = self::key();
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }

        return 'v1.' . base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        if (!str_starts_with($payload, 'v1.')) {
            throw new RuntimeException('Unsupported ciphertext version');
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Malformed ciphertext');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (tampered payload or wrong key)');
        }

        return $plain;
    }

    /** Generates a base64 32-byte key for APP_KEY. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function keyFingerprint(): string
    {
        return substr(hash('sha256', self::key()), 0, 12);
    }

    private static function key(): string
    {
        $encoded = (string) Config::get('app.key', '');
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY must be a base64 encoded 32-byte key. Run: php bin/console key:generate');
        }

        return $key;
    }
}

/** Deterministic HMAC index for encrypted columns that must stay searchable. */
final class BlindIndex
{
    public static function email(string $email): string
    {
        return self::make('email:' . mb_strtolower(trim($email)));
    }

    public static function value(string $value): string
    {
        return self::make(mb_strtolower(trim($value)));
    }

    private static function make(string $input): string
    {
        $key = (string) Config::get('app.blind_index_key', '');
        if ($key === '') {
            throw new RuntimeException('BLIND_INDEX_KEY is not configured');
        }
        $raw = base64_decode($key, true);

        return hash_hmac('sha256', $input, $raw === false ? $key : $raw);
    }
}

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timeSlice = null): string
    {
        $timeSlice ??= (int) floor(time() / 30);
        $key = self::base32Decode($secret);
        $binary = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $slice = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $slice + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer)
        );
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }

    /** @return list<string> */
    public static function recoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
        }

        return $codes;
    }
}

final class Token
{
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
