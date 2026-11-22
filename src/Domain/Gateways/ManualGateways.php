<?php

declare(strict_types=1);

namespace App\Domain\Gateways;

use App\Core\HttpException;
use App\Core\Money;
use App\Core\Request;
use App\Domain\InsufficientFundsException;
use App\Domain\OrderService;
use App\Domain\PaymentGateway;
use App\Domain\WalletService;

/** Bank transfer: the order stays pending until an admin confirms the credit. */
final class BankTransferGateway implements PaymentGateway
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function code(): string
    {
        return 'bank';
    }

    public function label(): string
    {
        return 'Bonifico bancario';
    }

    public function description(): string
    {
        return __('gateway_bank_desc');
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['iban'] ?? '')) !== '';
    }

    public function supportsRecurring(): bool
    {
        return false;
    }

    public function start(array $order, array $invoice, string $returnUrl): array
    {
        return [
            'type' => 'instructions',
            'message' => __('bank_instructions', [
                'iban' => (string) $this->config['iban'],
                'holder' => (string) ($this->config['holder'] ?? config('app.name')),
                'bic' => (string) ($this->config['bic'] ?? '-'),
                'reference' => (string) $order['number'],
                'amount' => Money::format((int) $order['total_cents'], (string) $order['currency']),
            ]),
        ];
    }

    public function handleWebhook(Request $request): ?array
    {
        return null;
    }

    public function refund(array $payment, int $amountCents): void
    {
        // Refunds for bank transfers are executed manually by the accountant.
    }
}

/** Internal credit: debits the wallet in one locked transaction and marks paid. */
final class WalletGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'wallet';
    }

    public function label(): string
    {
        return 'Credito wallet';
    }

    public function description(): string
    {
        return __('gateway_wallet_desc');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsRecurring(): bool
    {
        return true;
    }

    public function start(array $order, array $invoice, string $returnUrl): array
    {
        try {
            WalletService::debit(
                (int) $order['user_id'],
                (int) $order['total_cents'],
                'purchase',
                'order:' . (int) $order['id'],
                ['order' => $order['number']]
            );
        } catch (InsufficientFundsException) {
            throw new HttpException(422, __('insufficient_funds'));
        }
        OrderService::markPaid((int) $order['id'], 'wallet', (int) $order['total_cents'], 'wallet:' . (int) $order['id']);

        return ['type' => 'paid', 'url' => $returnUrl . '?status=success'];
    }

    public function handleWebhook(Request $request): ?array
    {
        return null;
    }

    public function refund(array $payment, int $amountCents): void
    {
        WalletService::credit((int) $payment['user_id'], $amountCents, 'refund', 'order:' . (int) ($payment['order_id'] ?? 0));
    }
}
