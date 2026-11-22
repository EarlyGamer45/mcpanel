<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\BlindIndex;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Hasher;
use App\Core\HttpException;
use App\Core\Json;

/**
 * Users' personal data is encrypted at the application layer; the e-mail blind
 * index keeps login and search possible without decrypting the whole table.
 */
final class UserRepository
{
    /** @param array<string,mixed> $extra */
    public static function create(string $username, string $email, string $password, array $extra = []): array
    {
        $email = mb_strtolower(trim($email));
        if (self::findByEmail($email) !== null) {
            throw new HttpException(422, __('email_taken'));
        }
        $db = App::db();
        $id = $db->insert('users', array_merge([
            'username' => $username,
            'email_enc' => Crypto::encrypt($email),
            'email_index' => BlindIndex::email($email),
            'password_hash' => Hasher::hash($password),
            'role' => 'customer',
            'status' => 'active',
            'country' => strtoupper((string) ($extra['country'] ?? 'IT')),
            'locale' => (string) ($extra['locale'] ?? config('app.locale', 'en')),
            'currency' => (string) config('app.currency', 'EUR'),
            'referral_code' => self::generateReferralCode($username),
            'created_at' => Database::now(),
        ], self::encryptedFields($extra)));

        return $db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return App::db()->selectOne(
            'SELECT * FROM users WHERE email_index = :index AND deleted_at IS NULL LIMIT 1',
            ['index' => BlindIndex::email($email)]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return App::db()->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * Decrypts the profile for display. Never expose this array to JSON endpoints
     * without filtering it first.
     *
     * @param array<string,mixed> $user
     * @return array<string,string>
     */
    public static function present(array $user): array
    {
        $decrypt = static function (?string $value): string {
            if ($value === null || $value === '') {
                return '';
            }
            try {
                return (string) Crypto::decrypt($value);
            } catch (\Throwable) {
                return '';
            }
        };

        return [
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['username'] ?? ''),
            'email' => $decrypt($user['email_enc'] ?? null),
            'company' => $decrypt($user['company_enc'] ?? null),
            'vat' => $decrypt($user['vat_number_enc'] ?? null),
            'address' => $decrypt($user['address_enc'] ?? null),
            'city' => $decrypt($user['city_enc'] ?? null),
            'postal_code' => $decrypt($user['postal_code_enc'] ?? null),
            'phone' => $decrypt($user['phone_enc'] ?? null),
            'country' => (string) ($user['country'] ?? 'IT'),
            'locale' => (string) ($user['locale'] ?? 'en'),
            'currency' => (string) ($user['currency'] ?? 'EUR'),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function updateProfile(int $id, array $data): void
    {
        $update = self::encryptedFields($data);
        foreach (['username', 'country', 'locale', 'timezone', 'currency'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        $update['updated_at'] = Database::now();
        App::db()->update('users', $update, 'id = :id', ['id' => $id]);
    }

    public static function setPassword(int $id, string $password): void
    {
        App::db()->update('users', [
            'password_hash' => Hasher::hash($password),
            'updated_at' => Database::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public const STATUSES = ['active', 'blocked', 'disabled'];

    /** Active, blocked (by staff, e.g. abuse) or disabled (account switched off). */
    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status: ' . $status);
        }
        $update = ['status' => $status, 'updated_at' => Database::now()];
        if ($status === 'active') {
            $update['failed_logins'] = 0;
            $update['locked_until'] = null;
        }
        App::db()->update('users', $update, 'id = :id', ['id' => $id]);
        if ($status !== 'active') {
            self::revokeSessions($id);
        }
    }

    /** Signs the user out everywhere, optionally keeping one session (the current one). */
    public static function revokeSessions(int $id, ?string $exceptSessionId = null): void
    {
        $sql = 'UPDATE user_sessions SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL';
        $params = ['now' => Database::now(), 'id' => $id];
        if ($exceptSessionId !== null) {
            $sql .= ' AND session_id <> :sid';
            $params['sid'] = $exceptSessionId;
        }
        App::db()->execute($sql, $params);
    }

    public static function changeEmail(int $id, string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, __('email_invalid'));
        }
        $existing = self::findByEmail($email);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            throw new HttpException(422, __('email_taken'));
        }
        App::db()->update('users', [
            'email_enc' => Crypto::encrypt($email),
            'email_index' => BlindIndex::email($email),
            'updated_at' => Database::now(),
        ], 'id = :id', ['id' => $id]);
    }

    /** Right to erasure: personal data is replaced, invoices are retained. */
    public static function anonymise(int $id): void
    {
        $db = App::db();
        $db->transaction(static function (Database $db) use ($id): void {
            $db->update('users', [
                'username' => 'deleted-' . $id,
                'email_enc' => Crypto::encrypt('deleted-' . $id . '@invalid.local'),
                'email_index' => BlindIndex::email('deleted-' . $id . '@invalid.local'),
                'password_hash' => Hasher::hash(bin2hex(random_bytes(16))),
                'company_enc' => null,
                'vat_number_enc' => null,
                'vat_number_index' => null,
                'address_enc' => null,
                'city_enc' => null,
                'postal_code_enc' => null,
                'phone_enc' => null,
                'totp_secret_enc' => null,
                'totp_enabled' => 0,
                'recovery_codes_enc' => null,
                'status' => 'deleted',
                'deleted_at' => Database::now(),
            ], 'id = :id', ['id' => $id]);
        });
    }

    /** @return array<string,mixed> */
    public static function export(int $id): array
    {
        $db = App::db();
        $user = self::find($id);
        if ($user === null) {
            throw new HttpException(404, 'User not found');
        }

        return [
            'profile' => self::present($user),
            'services' => $db->select('SELECT name, status, ram_mb, cpu_percent, disk_mb, price_cents, next_due_at FROM services WHERE user_id = :id', ['id' => $id]),
            'invoices' => $db->select('SELECT number, status, total_cents, currency, created_at FROM invoices WHERE user_id = :id', ['id' => $id]),
            'tickets' => $db->select('SELECT number, subject, status, created_at FROM tickets WHERE user_id = :id', ['id' => $id]),
            'wallet' => $db->select('SELECT amount_cents, type, created_at FROM wallet_transactions wt JOIN wallets w ON w.id = wt.wallet_id WHERE w.user_id = :id', ['id' => $id]),
            'exported_at' => gmdate('c'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function search(string $term, int $limit = 50): array
    {
        $db = App::db();
        if (trim($term) === '') {
            return $db->select('SELECT * FROM users WHERE deleted_at IS NULL ORDER BY id DESC LIMIT :limit', ['limit' => $limit]);
        }
        $byEmail = self::findByEmail($term);
        if ($byEmail !== null) {
            return [$byEmail];
        }
        if (ctype_digit($term)) {
            $byId = self::find((int) $term);

            return $byId === null ? [] : [$byId];
        }

        return $db->select('SELECT * FROM users WHERE username LIKE :term AND deleted_at IS NULL ORDER BY id DESC LIMIT :limit', ['term' => '%' . $term . '%', 'limit' => $limit]);
    }

    /** @param array<string,mixed> $data */
    private static function encryptedFields(array $data): array
    {
        $map = [
            'address' => 'address_enc',
            'city' => 'city_enc',
            'postal_code' => 'postal_code_enc',
            'phone' => 'phone_enc',
            'company' => 'company_enc',
        ];
        $fields = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key] === '' ? null : Crypto::encrypt((string) $data[$key]);
            }
        }
        if (array_key_exists('vat', $data)) {
            $vat = strtoupper(trim((string) $data['vat']));
            $fields['vat_number_enc'] = $vat === '' ? null : Crypto::encrypt($vat);
            $fields['vat_number_index'] = $vat === '' ? null : BlindIndex::value($vat);
        }
        if (array_key_exists('email', $data)) {
            $email = mb_strtolower(trim((string) $data['email']));
            $fields['email_enc'] = Crypto::encrypt($email);
            $fields['email_index'] = BlindIndex::email($email);
        }

        return $fields;
    }

    private static function generateReferralCode(string $username): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $username) ?? 'PLAYER', 0, 6));
        if (strlen($base) < 4) {
            $base = str_pad($base, 4, 'X');
        }

        return $base . strtoupper(bin2hex(random_bytes(2)));
    }

    /** @return array<string,mixed> */
    public static function dashboardStats(int $userId): array
    {
        $db = App::db();

        return [
            'active_services' => (int) $db->value('SELECT COUNT(*) FROM services WHERE user_id = :id AND status = :status', ['id' => $userId, 'status' => 'active']),
            'open_tickets' => (int) $db->value('SELECT COUNT(*) FROM tickets WHERE user_id = :id AND status <> :closed', ['id' => $userId, 'closed' => 'closed']),
            'unpaid_invoices' => (int) $db->value('SELECT COUNT(*) FROM invoices WHERE user_id = :id AND status = :pending', ['id' => $userId, 'pending' => 'pending']),
            'wallet' => WalletService::balance($userId),
        ];
    }
}
