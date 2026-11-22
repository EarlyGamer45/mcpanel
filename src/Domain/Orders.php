<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Events;
use App\Core\HttpException;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Queue;
use App\Core\Vat;

final class OrderStateMachine
{
    public const STATES = ['pending_payment', 'review', 'paid', 'provisioning', 'active', 'cancelled', 'refunded', 'failed'];

    private const TRANSITIONS = [
        'pending_payment' => ['review', 'paid', 'cancelled', 'failed'],
        'review' => ['paid', 'cancelled', 'failed'],
        'paid' => ['provisioning', 'active', 'refunded', 'failed'],
        'provisioning' => ['active', 'failed'],
        'active' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
        'failed' => ['paid', 'cancelled'],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (!self::can($from, $to)) {
            throw new \RuntimeException(sprintf('Invalid order transition %s -> %s', $from, $to));
        }
    }
}

final class OrderService
{
    /**
     * Creates an order plus its invoice in a single transaction.
     *
     * @param array<string,mixed> $user
     * @param list<array<string,mixed>> $items cart items (product_id, cycle, config, price_cents)
     * @return array{order:array<string,mixed>,invoice:array<string,mixed>,risk:int}
     */
    public static function create(array $user, array $items, string $couponCode = '', array $meta = []): array
    {
        if ($items === []) {
            throw new HttpException(422, 'Cart is empty');
        }
        $db = App::db();

        return $db->transaction(function (Database $db) use ($user, $items, $couponCode, $meta): array {
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += (int) $item['price_cents'];
            }
            $coupon = null;
            $discount = 0;
            if (trim($couponCode) !== '') {
                $coupon = Coupons::validate($couponCode, $subtotal, (int) ($items[0]['product_id'] ?? 0), $user);
                if ($coupon !== null) {
                    $discount = Coupons::discount($coupon, $subtotal);
                }
            }
            $gross = max(0, $subtotal - $discount);

            $customer = UserRepository::present($user);
            $country = strtoupper((string) ($customer['country'] ?? 'IT'));
            $isBusiness = trim((string) ($customer['vat'] ?? '')) !== '';
            $treatment = Vat::treatment(self::sellerCountry(), $country, $isBusiness, $isBusiness);

            $risk = RiskEngine::score($user, $items, $meta);
            $status = $risk >= (int) App::setting('risk_review_threshold', 70) ? 'review' : 'pending_payment';

            $year = (int) gmdate('Y');
            $sequence = \App\Core\Sequences::next('order:' . $year);
            $orderId = $db->insert('orders', [
                'number' => sprintf('ORD-%d-%05d', $year, $sequence),
                'user_id' => (int) $user['id'],
                'status' => $status,
                'currency' => (string) config('app.currency', 'EUR'),
                'subtotal_cents' => $gross,
                'discount_cents' => $discount,
                'tax_cents' => 0,
                'total_cents' => $gross,
                'coupon_id' => $coupon === null ? null : (int) $coupon['id'],
                'risk_score' => $risk,
                'meta_json' => Json::encode($meta),
                'created_at' => Database::now(),
            ]);

            $lines = [];
            foreach ($items as $item) {
                $product = Catalog::productById((int) $item['product_id']);
                $config = $item['config'] ?? Json::decode((string) ($item['config_json'] ?? ''));
                $description = Services::label($product, $config, (string) $item['cycle']);
                $quantity = 1;
                $lineTotal = (int) $item['price_cents'];
                $db->insert('order_items', [
                    'order_id' => $orderId,
                    'product_id' => (int) $item['product_id'],
                    'description' => $description,
                    'cycle' => (string) $item['cycle'],
                    'config_json' => Json::encode($config),
                    'unit_cents' => $lineTotal,
                    'quantity' => $quantity,
                    'total_cents' => $lineTotal,
                ]);
                $lines[] = ['description' => $description, 'quantity' => $quantity, 'unit_cents' => $lineTotal];
            }

            $invoice = InvoiceService::issue(
                (int) $user['id'],
                $orderId,
                $lines,
                $customer,
                $treatment,
                'Order ' . sprintf('ORD-%d-%05d', $year, $sequence)
            );
            $db->update('orders', [
                'invoice_id' => (int) $invoice['id'],
                'tax_cents' => (int) $invoice['tax_cents'],
                'subtotal_cents' => (int) $invoice['subtotal_cents'],
                'total_cents' => (int) $invoice['total_cents'],
                'updated_at' => Database::now(),
            ], 'id = :id', ['id' => $orderId]);

            if ($coupon !== null && $discount > 0) {
                Coupons::redeem($coupon, (int) $user['id'], $orderId, $discount);
            }

            Audit::log('order.created', 'order', $orderId, ['total' => $gross, 'risk' => $risk]);

            return [
                'order' => $db->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]) ?? [],
                'invoice' => $invoice,
                'risk' => $risk,
            ];
        });
    }

    /**
     * Idempotent payment confirmation. Safe to call twice with the same gateway
     * event: the order is only provisioned once.
     */
    public static function markPaid(int $orderId, string $gateway, int $amountCents, string $externalId, array $payload = []): bool
    {
        $db = App::db();
        $outcome = $db->transaction(function (Database $db) use ($orderId, $gateway, $amountCents, $externalId, $payload): ?array {
            $order = $db->selectOne('SELECT * FROM orders WHERE id = :id' . $db->lockClause(), ['id' => $orderId]);
            if ($order === null) {
                throw new HttpException(404, 'Order not found');
            }
            $idempotency = $gateway . ':' . ($externalId !== '' ? $externalId : 'order-' . $orderId);
            $existing = $db->selectOne('SELECT id FROM payments WHERE idempotency_key = :key', ['key' => $idempotency]);
            if (in_array((string) $order['status'], ['paid', 'provisioning', 'active'], true)) {
                return null; // already processed
            }
            if ($amountCents > 0 && $amountCents + 1 < (int) $order['total_cents']) {
                Logger::error('payments.amount_mismatch', ['order' => $orderId, 'expected' => (int) $order['total_cents'], 'received' => $amountCents]);
                throw new HttpException(422, 'Paid amount does not match the order total');
            }
            OrderStateMachine::assert((string) $order['status'], 'paid');
            $db->update('orders', ['status' => 'paid', 'paid_at' => Database::now(), 'updated_at' => Database::now()], 'id = :id', ['id' => $orderId]);
            if (!empty($order['invoice_id'])) {
                InvoiceService::markPaid((int) $order['invoice_id']);
            }
            if ($existing === null) {
                $db->insert('payments', [
                    'user_id' => (int) $order['user_id'],
                    'order_id' => $orderId,
                    'invoice_id' => $order['invoice_id'] ?? null,
                    'gateway' => $gateway,
                    'external_id' => $externalId !== '' ? $externalId : null,
                    'amount_cents' => $amountCents,
                    'currency' => (string) $order['currency'],
                    'status' => 'succeeded',
                    'idempotency_key' => $idempotency,
                    'meta_json' => Json::encode($payload),
                    'created_at' => Database::now(),
                ]);
            }
            $serviceIds = Services::provisionFromOrder($order);

            return ['order' => $order, 'services' => $serviceIds];
        });

        if ($outcome === null) {
            return false;
        }
        foreach ($outcome['services'] as $serviceId) {
            Queue::push(\App\Domain\Jobs\ProvisionServerJob::class, ['service_id' => $serviceId], 0, 'provisioning');
        }
        Events::dispatch('order.paid', ['order_id' => $orderId, 'service_ids' => $outcome['services']]);
        Audit::log('order.paid', 'order', $orderId, ['gateway' => $gateway, 'amount' => $amountCents]);

        return true;
    }

    public static function transition(int $orderId, string $to, string $reason = '', ?int $actorId = null): void
    {
        $db = App::db();
        $order = $db->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            throw new HttpException(404, 'Order not found');
        }
        OrderStateMachine::assert((string) $order['status'], $to);
        $db->update('orders', ['status' => $to, 'updated_at' => Database::now()], 'id = :id', ['id' => $orderId]);
        Audit::log('order.transition', 'order', $orderId, ['from' => $order['status'], 'to' => $to, 'reason' => $reason, 'actor' => $actorId]);
    }

    public static function sellerCountry(): string
    {
        return strtoupper((string) App::setting('company_country', 'IT'));
    }
}

/**
 * Heuristic antifraud score (0-100). Orders above the configured threshold go to
 * manual review instead of the payment page.
 */
final class RiskEngine
{
    private const DISPOSABLE = ['mailinator.com', 'guerrillamail.com', 'tempmail.com', '10minutemail.com', 'yopmail.com', 'trashmail.com', 'sharklasers.com'];

    /** @param list<array<string,mixed>> $items */
    public static function score(array $user, array $items, array $meta): int
    {
        $score = 0;
        $email = strtolower((string) ($meta['email'] ?? ''));
        $domain = str_contains($email, '@') ? explode('@', $email)[1] : '';
        if ($domain !== '' && in_array($domain, self::DISPOSABLE, true)) {
            $score += 60;
        }
        $ip = (string) ($meta['ip'] ?? '');
        if ($ip !== '') {
            $recent = (int) App::db()->value(
                'SELECT COUNT(*) FROM audit_logs WHERE action = :action AND ip = :ip AND created_at > :since',
                ['action' => 'order.created', 'ip' => $ip, 'since' => gmdate('Y-m-d H:i:s', time() - 86400)]
            );
            if ($recent >= 3) {
                $score += 25;
            }
        }
        if (!empty($meta['country_mismatch'])) {
            $score += 20;
        }
        if ($items !== []) {
            $total = 0;
            foreach ($items as $item) {
                $total += (int) $item['price_cents'];
            }
            if ($total > 30000) {
                $score += 10;
            }
        }

        return min(100, $score);
    }
}
