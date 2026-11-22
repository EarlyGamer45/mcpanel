<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Database;
use App\Core\Events;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Sequences;
use App\Core\Vat;

/**
 * Scheduled business rules: renewal invoices, dunning, suspension, termination
 * and housekeeping. Every method is safe to run twice (idempotent guards on
 * existing open orders/invoices).
 */
final class Automation
{
    public static function renewInvoices(?int $daysAhead = null): int
    {
        $daysAhead ??= (int) App::setting('renew_notice_days', 7);
        $db = App::db();
        $services = $db->select(
            'SELECT * FROM services WHERE status = :active AND auto_renew = 1 AND next_due_at IS NOT NULL AND next_due_at <= :limit',
            ['active' => 'active', 'limit' => gmdate('Y-m-d H:i:s', time() + $daysAhead * 86400)]
        );
        $created = 0;
        foreach ($services as $service) {
            $pending = $db->selectOne(
                'SELECT oi.id FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE oi.service_id = :service AND o.status IN (:pending, :review) LIMIT 1',
                ['service' => (int) $service['id'], 'pending' => 'pending_payment', 'review' => 'review']
            );
            if ($pending !== null) {
                continue;
            }
            $user = UserRepository::find((int) $service['user_id']);
            if ($user === null || (string) $user['status'] !== 'active') {
                continue;
            }
            try {
                $order = self::createRenewalOrder($service, $user);
                $created++;
                Events::dispatch('order.created', ['order_id' => (int) $order['id'], 'renewal' => true]);
                if (App::setting('auto_charge_wallet', true) === true && WalletService::balance((int) $user['id']) >= (int) $order['total_cents']) {
                    WalletService::debit((int) $user['id'], (int) $order['total_cents'], 'renewal', 'order:' . (int) $order['id']);
                    OrderService::markPaid((int) $order['id'], 'wallet', (int) $order['total_cents'], 'wallet:renewal:' . (int) $order['id']);
                }
            } catch (\Throwable $e) {
                Logger::error('automation.renewal_failed', ['service' => (int) $service['id'], 'error' => $e->getMessage()]);
            }
        }

        return $created;
    }

    public static function suspendOverdue(): int
    {
        $grace = (int) App::setting('grace_days', 3);
        $db = App::db();
        $rows = $db->select(
            'SELECT * FROM services WHERE status = :active AND next_due_at IS NOT NULL AND next_due_at < :cutoff',
            ['active' => 'active', 'cutoff' => gmdate('Y-m-d H:i:s', time() - $grace * 86400)]
        );
        $suspended = 0;
        foreach ($rows as $service) {
            try {
                ProvisioningService::suspend($service);
                $suspended++;
            } catch (\Throwable $e) {
                Logger::error('automation.suspend_failed', ['service' => (int) $service['id'], 'error' => $e->getMessage()]);
            }
        }

        return $suspended;
    }

    public static function terminateExpired(): int
    {
        $after = (int) App::setting('terminate_after_days', 14);
        $db = App::db();
        $rows = $db->select(
            'SELECT * FROM services WHERE status = :suspended AND suspended_at IS NOT NULL AND suspended_at < :cutoff',
            ['suspended' => 'suspended', 'cutoff' => gmdate('Y-m-d H:i:s', time() - $after * 86400)]
        );
        $terminated = 0;
        foreach ($rows as $service) {
            try {
                ProvisioningService::terminate($service);
                $terminated++;
            } catch (\Throwable $e) {
                Logger::error('automation.terminate_failed', ['service' => (int) $service['id'], 'error' => $e->getMessage()]);
            }
        }

        return $terminated;
    }

    public static function closeInactiveTickets(int $days = 7): int
    {
        return App::db()->execute(
            'UPDATE tickets SET status = :closed, closed_at = :now WHERE status IN (:open, :waiting) AND updated_at < :cutoff',
            [
                'closed' => 'closed',
                'now' => Database::now(),
                'open' => 'open',
                'waiting' => 'waiting_customer',
                'cutoff' => gmdate('Y-m-d H:i:s', time() - $days * 86400),
            ]
        );
    }

    /** Reminds customers with an open cart and no order after 24h. */
    public static function recoverCarts(int $limit = 50): int
    {
        $db = App::db();
        $rows = $db->select(
            'SELECT c.*, (SELECT COUNT(*) FROM cart_items ci WHERE ci.cart_id = c.id) AS items
             FROM carts c
             WHERE c.status = :open AND c.reminder_sent_at IS NULL AND c.email_enc IS NOT NULL
               AND c.updated_at < :cutoff LIMIT :limit',
            ['open' => 'open', 'cutoff' => gmdate('Y-m-d H:i:s', time() - 86400), 'limit' => $limit]
        );
        $sent = 0;
        foreach ($rows as $cart) {
            if ((int) $cart['items'] === 0) {
                continue;
            }
            try {
                $email = (string) \App\Core\Crypto::decrypt($cart['email_enc']);
                if ($email !== '') {
                    \App\Core\Queue::push(\App\Domain\Jobs\SendMailJob::class, [
                        'to' => \App\Core\Crypto::encrypt($email),
                        'subject' => __('mail_cart_subject'),
                        'html' => \App\Core\Mailer::template(__('mail_cart_heading'), '<p>' . __('mail_cart_body') . '</p>', [
                            'cta_url' => url('/cart'),
                            'cta_label' => __('mail_cart_cta'),
                        ]),
                        'template' => 'cart_recovery',
                    ]);
                    $sent++;
                }
                $db->update('carts', ['reminder_sent_at' => Database::now(), 'status' => 'abandoned'], 'id = :id', ['id' => (int) $cart['id']]);
            } catch (\Throwable $e) {
                Logger::error('automation.cart_failed', ['cart' => (int) $cart['id'], 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** @return array<string,int> */
    public static function prune(): array
    {
        $db = App::db();
        $retention = (int) App::setting('log_retention_days', 90);

        return [
            'jobs' => $db->execute('DELETE FROM jobs WHERE failed_at IS NOT NULL AND failed_at < :cutoff', ['cutoff' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]),
            'audit_logs' => $db->execute('DELETE FROM audit_logs WHERE created_at < :cutoff', ['cutoff' => gmdate('Y-m-d H:i:s', time() - max(365, $retention) * 86400)]),
            'mail_log' => $db->execute('DELETE FROM mail_log WHERE created_at < :cutoff', ['cutoff' => gmdate('Y-m-d H:i:s', time() - $retention * 86400)]),
            'webhook_events' => $db->execute('DELETE FROM webhook_events WHERE created_at < :cutoff', ['cutoff' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]),
        ];
    }

    /**
     * Creates the renewal order + invoice for a service.
     *
     * @param array<string,mixed> $service
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function createRenewalOrder(array $service, array $user): array
    {
        $db = App::db();

        return $db->transaction(static function (Database $db) use ($service, $user): array {
            $customer = UserRepository::present($user);
            $isBusiness = trim((string) $customer['vat']) !== '';
            $treatment = Vat::treatment(OrderService::sellerCountry(), (string) $customer['country'], $isBusiness, $isBusiness);
            $total = (int) $service['price_cents'];
            $year = (int) gmdate('Y');
            $number = sprintf('ORD-%d-%05d', $year, Sequences::next('order:' . $year));
            $orderId = $db->insert('orders', [
                'number' => $number,
                'user_id' => (int) $user['id'],
                'status' => 'pending_payment',
                'currency' => (string) $service['currency'],
                'subtotal_cents' => $total,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'total_cents' => $total,
                'meta_json' => Json::encode(['renewal' => true, 'service_id' => (int) $service['id']]),
                'created_at' => Database::now(),
            ]);
            $db->insert('order_items', [
                'order_id' => $orderId,
                'product_id' => (int) $service['product_id'],
                'service_id' => (int) $service['id'],
                'description' => 'Rinnovo · ' . $service['name'],
                'cycle' => (string) $service['cycle'],
                'config_json' => $service['config_json'],
                'unit_cents' => $total,
                'quantity' => 1,
                'total_cents' => $total,
            ]);
            $invoice = InvoiceService::issue(
                (int) $user['id'],
                $orderId,
                [['description' => __('renewal_of') . ' ' . $service['name'], 'quantity' => 1, 'unit_cents' => $total]],
                $customer,
                $treatment,
                'Renewal ' . $number,
                $service['next_due_at'] !== null ? gmdate('Y-m-d H:i:s', strtotime((string) $service['next_due_at'] . ' UTC')) : null
            );
            $db->update('orders', [
                'invoice_id' => (int) $invoice['id'],
                'subtotal_cents' => (int) $invoice['subtotal_cents'],
                'tax_cents' => (int) $invoice['tax_cents'],
                'total_cents' => (int) $invoice['total_cents'],
                'updated_at' => Database::now(),
            ], 'id = :id', ['id' => $orderId]);

            return $db->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]) ?? [];
        });
    }
}
