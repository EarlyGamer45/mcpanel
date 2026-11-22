<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Schema;
use App\Core\Str;

/**
 * Payment driver contract. Adding a gateway means implementing this interface
 * and registering it in GatewayRegistry: no core code changes.
 */
interface PaymentGateway
{
    public function code(): string;

    public function label(): string;

    public function description(): string;

    public function isConfigured(): bool;

    /** @return bool true when the gateway can charge saved methods on renewal */
    public function supportsRecurring(): bool;

    /**
     * Starts a payment.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $invoice
     * @return array{type:string,url?:string,message?:string}
     */
    public function start(array $order, array $invoice, string $returnUrl): array;

    /**
     * Verifies and normalises a webhook. Must throw ApiException on bad
     * signatures. Returns null when the event is irrelevant.
     *
     * @return array{event_id:string,type:string,status:string,order_id?:int,amount_cents?:int,payment_id?:string}|null
     */
    public function handleWebhook(Request $request): ?array;

    public function refund(array $payment, int $amountCents): void;
}

final class GatewayRegistry
{
    /** @return list<PaymentGateway> */
    public static function all(): array
    {
        $config = (array) config('payments', []);
        $gateways = [
            new Gateways\StripeGateway((array) ($config['stripe'] ?? [])),
            new Gateways\PayPalGateway((array) ($config['paypal'] ?? [])),
            new Gateways\BankTransferGateway((array) ($config['bank'] ?? [])),
            new Gateways\WalletGateway(),
        ];
        $enabled = [];
        foreach ($gateways as $gateway) {
            $flag = App::setting('gateway_enabled.' . $gateway->code(), true);
            if ($flag === false || $flag === 0 || $flag === '0') {
                continue;
            }
            $enabled[] = $gateway;
        }

        return $enabled;
    }

    public static function get(string $code): PaymentGateway
    {
        foreach (self::all() as $gateway) {
            if ($gateway->code() === $code) {
                return $gateway;
            }
        }
        throw new HttpException(404, __('payment_method_unavailable'));
    }

    /** @return list<PaymentGateway> gateways usable right now */
    public static function usable(): array
    {
        return array_values(array_filter(self::all(), static fn (PaymentGateway $gateway): bool => $gateway->isConfigured()));
    }
}

final class PaymentService
{
    /**
     * Idempotent webhook processing: the event row is inserted once (unique
     * gateway+external id), then the order is marked paid at most once.
     *
     * @return array{ok:bool,duplicate?:bool,ignored?:bool}
     */
    public static function processWebhook(string $gatewayCode, Request $request): array
    {
        $gateway = GatewayRegistry::get($gatewayCode);
        $normalized = $gateway->handleWebhook($request);
        if ($normalized === null) {
            return ['ok' => true, 'ignored' => true];
        }
        $db = App::db();
        $eventId = Str::limit((string) $normalized['event_id'], 190);
        $existing = $db->selectOne(
            'SELECT * FROM webhook_events WHERE gateway = :gateway AND external_id = :external LIMIT 1',
            ['gateway' => $gatewayCode, 'external' => $eventId]
        );
        if ($existing !== null && $existing['processed_at'] !== null) {
            return ['ok' => true, 'duplicate' => true];
        }
        if ($existing === null) {
            try {
                $db->insert('webhook_events', [
                    'gateway' => $gatewayCode,
                    'external_id' => $eventId,
                    'event_type' => Str::limit((string) $normalized['type'], 80),
                    'payload_json' => Str::limit((string) $request->rawBody(), 16000),
                    'created_at' => Database::now(),
                ]);
            } catch (\PDOException $e) {
                if (!Schema::isBenign($e)) {
                    throw $e;
                }
            }
        }

        try {
            if (($normalized['status'] ?? '') === 'succeeded' && (int) ($normalized['order_id'] ?? 0) > 0) {
                OrderService::markPaid(
                    (int) $normalized['order_id'],
                    $gatewayCode,
                    (int) ($normalized['amount_cents'] ?? 0),
                    (string) ($normalized['payment_id'] ?? $eventId),
                    $normalized
                );
            }
            $db->update('webhook_events', ['processed_at' => Database::now(), 'error' => null], 'gateway = :gateway AND external_id = :external', ['gateway' => $gatewayCode, 'external' => $eventId]);

            return ['ok' => true];
        } catch (\Throwable $e) {
            $db->update('webhook_events', ['error' => Str::limit($e->getMessage(), 250)], 'gateway = :gateway AND external_id = :external', ['gateway' => $gatewayCode, 'external' => $eventId]);
            Logger::error('payments.webhook_failed', ['gateway' => $gatewayCode, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function refund(int $orderId, int $amountCents, string $reason = ''): void
    {
        $db = App::db();
        $order = $db->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            throw new HttpException(404, 'Order not found');
        }
        $payment = $db->selectOne('SELECT * FROM payments WHERE order_id = :order AND status = :status ORDER BY id DESC LIMIT 1', ['order' => $orderId, 'status' => 'succeeded']);
        if ($payment !== null) {
            $gateway = GatewayRegistry::get((string) $payment['gateway']);
            $gateway->refund($payment, $amountCents);
            $db->update('payments', ['status' => 'refunded', 'updated_at' => Database::now()], 'id = :id', ['id' => (int) $payment['id']]);
        }
        if (!empty($order['invoice_id'])) {
            InvoiceService::creditNote((int) $order['invoice_id'], $amountCents, $reason);
        }
        $db->update('orders', ['status' => 'refunded', 'updated_at' => Database::now()], 'id = :id', ['id' => $orderId]);
        WalletService::credit((int) $order['user_id'], $amountCents, 'refund', 'order:' . $orderId, ['reason' => $reason]);
        \App\Core\Audit::log('payment.refunded', 'order', $orderId, ['amount' => $amountCents, 'reason' => $reason]);
    }

    /** @return array<string,mixed>|null */
    public static function lastPaymentFor(int $orderId): ?array
    {
        return App::db()->selectOne('SELECT * FROM payments WHERE order_id = :order ORDER BY id DESC LIMIT 1', ['order' => $orderId]);
    }

    /** @param array<string,mixed> $order */
    public static function returnUrl(array $order): string
    {
        return url('/checkout/return/' . (int) $order['id']);
    }
}
