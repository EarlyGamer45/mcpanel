<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Core\Money;
use App\Core\Session;
use App\Core\Token;

final class Cart
{
    /** @return array<string,mixed> */
    public static function current(): array
    {
        $session = Session::instance();
        $token = (string) $session->get('_cart_token', '');
        $db = App::db();
        $cart = null;
        if ($token !== '') {
            $cart = $db->selectOne('SELECT * FROM carts WHERE token = :token LIMIT 1', ['token' => $token]);
        }
        if ($cart === null) {
            $token = Token::generate(16);
            $session->put('_cart_token', $token);
            $id = $db->insert('carts', [
                'token' => $token,
                'user_id' => Auth::id(),
                'currency' => (string) config('app.currency', 'EUR'),
                'status' => 'open',
                'created_at' => Database::now(),
                'updated_at' => Database::now(),
            ]);
            $cart = $db->selectOne('SELECT * FROM carts WHERE id = :id', ['id' => $id]) ?? [];
        }

        return $cart;
    }

    /** @return list<array<string,mixed>> */
    public static function items(?array $cart = null): array
    {
        $cart ??= self::current();
        if (empty($cart['id'])) {
            return [];
        }
        $rows = App::db()->select(
            'SELECT ci.*, p.name AS product_name, p.slug AS product_slug, l.name AS location_name
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             LEFT JOIN locations l ON l.id = ci.location_id
             WHERE ci.cart_id = :cart ORDER BY ci.id ASC',
            ['cart' => (int) $cart['id']]
        );
        foreach ($rows as &$row) {
            $row['config'] = Json::decode($row['config_json'] ?? null);
            $row['cycle_label'] = __((string) $row['cycle']);
        }

        return $rows;
    }

    /** @param array<string,mixed> $quote */
    public static function add(int $productId, array $quote, ?int $locationId = null): int
    {
        $cart = self::current();
        $config = $quote['config'];
        $config['addon_ids'] = $quote['addon_ids'];

        return App::db()->insert('cart_items', [
            'cart_id' => (int) $cart['id'],
            'product_id' => $productId,
            'location_id' => $locationId,
            'cycle' => (string) $quote['cycle'],
            'config_json' => Json::encode($config),
            'price_cents' => (int) $quote['total_cents'],
            'created_at' => Database::now(),
        ]);
    }

    public static function remove(int $itemId): void
    {
        $cart = self::current();
        App::db()->execute('DELETE FROM cart_items WHERE id = :id AND cart_id = :cart', ['id' => $itemId, 'cart' => (int) $cart['id']]);
    }

    public static function clear(): void
    {
        $cart = self::current();
        App::db()->execute('DELETE FROM cart_items WHERE cart_id = :cart', ['cart' => (int) $cart['id']]);
    }

    public static function count(): int
    {
        $cart = self::current();

        return (int) App::db()->value('SELECT COUNT(*) FROM cart_items WHERE cart_id = :cart', ['cart' => (int) $cart['id']]);
    }

    /** @param list<array<string,mixed>> $items */
    public static function total(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            $total += (int) $item['price_cents'];
        }

        return $total;
    }

    public static function attachUser(int $userId): void
    {
        $cart = self::current();
        App::db()->update('carts', ['user_id' => $userId, 'updated_at' => Database::now()], 'id = :id', ['id' => (int) $cart['id']]);
    }

    public static function attachEmail(string $email): void
    {
        $cart = self::current();
        App::db()->update('carts', ['email_enc' => \App\Core\Crypto::encrypt($email), 'updated_at' => Database::now()], 'id = :id', ['id' => (int) $cart['id']]);
    }

    public static function markConverted(): void
    {
        $cart = self::current();
        App::db()->update('carts', ['status' => 'converted', 'updated_at' => Database::now()], 'id = :id', ['id' => (int) $cart['id']]);
    }
}

final class Coupons
{
    /**
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>|null a valid coupon row or null
     */
    public static function validate(string $code, int $subtotalCents, int $productId, ?array $user = null): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        $db = App::db();
        $coupon = $db->selectOne('SELECT * FROM coupons WHERE code = :code AND active = 1 LIMIT 1', ['code' => $code]);
        if ($coupon === null) {
            return null;
        }
        $now = time();
        if (!empty($coupon['valid_from']) && strtotime((string) $coupon['valid_from'] . ' UTC') > $now) {
            return null;
        }
        if (!empty($coupon['valid_to']) && strtotime((string) $coupon['valid_to'] . ' UTC') < $now) {
            return null;
        }
        if ((int) $coupon['max_uses'] > 0 && (int) $coupon['uses'] >= (int) $coupon['max_uses']) {
            return null;
        }
        $appliesTo = Json::decode($coupon['applies_to_json'] ?? null);
        if ($appliesTo !== [] && !in_array($productId, array_map('intval', $appliesTo), true)) {
            return null;
        }
        if ($user !== null) {
            $used = (int) $db->value(
                'SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :coupon AND user_id = :user',
                ['coupon' => (int) $coupon['id'], 'user' => (int) $user['id']]
            );
            if ($used >= max(1, (int) $coupon['per_user_limit'])) {
                return null;
            }
            if ((int) $coupon['first_purchase_only'] === 1) {
                $orders = (int) $db->value('SELECT COUNT(*) FROM orders WHERE user_id = :user AND status <> :pending', ['user' => (int) $user['id'], 'pending' => 'pending_payment']);
                if ($orders > 0) {
                    return null;
                }
            }
        } elseif ((int) $coupon['first_purchase_only'] === 1) {
            return null;
        }
        if ($subtotalCents <= 0) {
            return null;
        }

        return $coupon;
    }

    /** @param array<string,mixed> $coupon */
    public static function discount(array $coupon, int $subtotalCents): int
    {
        $amount = match ((string) $coupon['type']) {
            'percent' => Money::divRound($subtotalCents * (int) $coupon['value'], 100),
            'fixed' => (int) $coupon['value'],
            default => 0,
        };

        return max(0, min($amount, $subtotalCents));
    }

    public static function redeem(array $coupon, int $userId, int $orderId, int $amountCents): void
    {
        $db = App::db();
        $db->insert('coupon_redemptions', [
            'coupon_id' => (int) $coupon['id'],
            'user_id' => $userId,
            'order_id' => $orderId,
            'amount_cents' => $amountCents,
            'created_at' => Database::now(),
        ]);
        $db->execute('UPDATE coupons SET uses = uses + 1 WHERE id = :id', ['id' => (int) $coupon['id']]);
    }
}
