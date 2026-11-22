<?php

declare(strict_types=1);

namespace LicenseServer;

use PDO;

final class Store
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS licenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                license_key TEXT NOT NULL UNIQUE,
                plan TEXT NOT NULL DEFAULT 'standard',
                status TEXT NOT NULL DEFAULT 'active',
                max_activations INTEGER NOT NULL DEFAULT 1,
                domain TEXT NULL,
                expires_at TEXT NULL,
                created_at TEXT NOT NULL,
                last_check_at TEXT NULL,
                notes TEXT NULL
            );
            CREATE TABLE IF NOT EXISTS activations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                license_id INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
                fingerprint TEXT NOT NULL,
                domain TEXT NULL,
                version TEXT NULL,
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                UNIQUE (license_id, fingerprint)
            );
            CREATE TABLE IF NOT EXISTS throttle (
                ip TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (ip, window_start)
            );
        SQL);
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE license_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);

        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO licenses (license_key, plan, status, max_activations, domain, expires_at, created_at, notes)
             VALUES (:license_key, :plan, :status, :max_activations, :domain, :expires_at, :created_at, :notes)'
        );
        $stmt->execute([
            'license_key' => $data['license_key'],
            'plan' => $data['plan'] ?? 'standard',
            'status' => $data['status'] ?? 'active',
            'max_activations' => $data['max_activations'] ?? 1,
            'domain' => $data['domain'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'notes' => $data['notes'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function activation(int $licenseId, string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM activations WHERE license_id = :id AND fingerprint = :fp LIMIT 1');
        $stmt->execute(['id' => $licenseId, 'fp' => $fingerprint]);

        return $stmt->fetch() ?: null;
    }

    public function activationCount(int $licenseId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM activations WHERE license_id = :id');
        $stmt->execute(['id' => $licenseId]);

        return (int) $stmt->fetchColumn();
    }

    public function touchActivation(int $licenseId, string $fingerprint, ?string $domain, ?string $version): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO activations (license_id, fingerprint, domain, version, first_seen_at, last_seen_at)
             VALUES (:id, :fp, :domain, :version, :now, :now)
             ON CONFLICT (license_id, fingerprint)
             DO UPDATE SET last_seen_at = excluded.last_seen_at, domain = excluded.domain, version = excluded.version'
        );
        $stmt->execute(['id' => $licenseId, 'fp' => $fingerprint, 'domain' => $domain, 'version' => $version, 'now' => $now]);
    }

    public function markChecked(int $licenseId): void
    {
        $stmt = $this->pdo->prepare('UPDATE licenses SET last_check_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $licenseId]);
    }

    public function setStatus(int $licenseId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE licenses SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $licenseId]);
    }

    /** Fixed-window throttle: returns false when the caller exceeded $limit/min. */
    public function allow(string $ip, int $limit = 30): bool
    {
        $window = (int) floor(time() / 60);
        $stmt = $this->pdo->prepare(
            'INSERT INTO throttle (ip, window_start, hits) VALUES (:ip, :window, 1)
             ON CONFLICT (ip, window_start) DO UPDATE SET hits = hits + 1'
        );
        $stmt->execute(['ip' => $ip, 'window' => $window]);
        $read = $this->pdo->prepare('SELECT hits FROM throttle WHERE ip = :ip AND window_start = :window');
        $read->execute(['ip' => $ip, 'window' => $window]);
        $hits = (int) $read->fetchColumn();
        if (random_int(1, 100) === 1) {
            $this->pdo->prepare('DELETE FROM throttle WHERE window_start < :old')->execute(['old' => $window - 10]);
        }

        return $hits <= $limit;
    }
}
