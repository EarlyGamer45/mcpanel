<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Database;
use App\Core\Json;

final class InsufficientFundsException extends \RuntimeException
{
}

/**
 * Every balance change happens inside a database transaction with a row lock:
 * two concurrent debits can never spend the same credit.
 */
final class WalletService
{
    /** @return array<string,mixed> */
    public static function wallet(int $userId): array
    {
        $db = App::db();
        $wallet = $db->selectOne('SELECT * FROM wallets WHERE user_id = :user LIMIT 1', ['user' => $userId]);
        if ($wallet !== null) {
            return $wallet;
        }
        try {
            $id = $db->insert('wallets', [
                'user_id' => $userId,
                'balance_cents' => 0,
                'currency' => (string) config('app.currency', 'EUR'),
                'updated_at' => Database::now(),
            ]);
        } catch (\PDOException $e) {
            if (!\App\Core\Schema::isBenign($e)) {
                throw $e;
            }
            $id = (int) $db->value('SELECT id FROM wallets WHERE user_id = :user', ['user' => $userId]);
        }

        return $db->selectOne('SELECT * FROM wallets WHERE id = :id', ['id' => $id]) ?? [];
    }

    public static function balance(int $userId): int
    {
        $row = App::db()->selectOne('SELECT balance_cents FROM wallets WHERE user_id = :user', ['user' => $userId]);

        return $row === null ? 0 : (int) $row['balance_cents'];
    }

    /** @param array<string,mixed> $meta */
    public static function credit(int $userId, int $amountCents, string $type, string $reference = '', array $meta = []): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive');
        }
        self::apply($userId, $amountCents, $type, $reference, $meta);
    }

    /** @param array<string,mixed> $meta */
    public static function debit(int $userId, int $amountCents, string $type, string $reference = '', array $meta = []): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive');
        }
        self::apply($userId, -$amountCents, $type, $reference, $meta);
    }

    /** @return list<array<string,mixed>> */
    public static function transactions(int $userId, int $limit = 50): array
    {
        return App::db()->select(
            'SELECT wt.* FROM wallet_transactions wt JOIN wallets w ON w.id = wt.wallet_id
             WHERE w.user_id = :user ORDER BY wt.id DESC LIMIT :limit',
            ['user' => $userId, 'limit' => $limit]
        );
    }

    /** @param array<string,mixed> $meta */
    private static function apply(int $userId, int $delta, string $type, string $reference, array $meta): void
    {
        $db = App::db();
        $db->transaction(static function (Database $db) use ($userId, $delta, $type, $reference, $meta): void {
            $wallet = $db->selectOne('SELECT * FROM wallets WHERE user_id = :user' . $db->lockClause(), ['user' => $userId]);
            if ($wallet === null) {
                $id = $db->insert('wallets', [
                    'user_id' => $userId,
                    'balance_cents' => 0,
                    'currency' => (string) config('app.currency', 'EUR'),
                    'updated_at' => Database::now(),
                ]);
                $wallet = $db->selectOne('SELECT * FROM wallets WHERE id = :id' . $db->lockClause(), ['id' => $id]) ?? [];
            }
            $balance = (int) $wallet['balance_cents'] + $delta;
            if ($balance < 0) {
                throw new InsufficientFundsException(__('insufficient_funds'));
            }
            $db->update('wallets', ['balance_cents' => $balance, 'updated_at' => Database::now()], 'id = :id', ['id' => (int) $wallet['id']]);
            $db->insert('wallet_transactions', [
                'wallet_id' => (int) $wallet['id'],
                'amount_cents' => $delta,
                'type' => $type,
                'reference' => $reference !== '' ? $reference : null,
                'balance_after_cents' => $balance,
                'meta_json' => Json::encode($meta),
                'created_at' => Database::now(),
            ]);
        });
    }
}
