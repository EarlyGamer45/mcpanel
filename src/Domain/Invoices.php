<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Money;
use App\Core\PdfDocument;
use App\Core\Vat;

final class InvoiceService
{
    /**
     * Issues an invoice inside the caller's transaction. $lines contain gross
     * amounts (VAT included for consumers); the tax is extracted, never added on
     * top of the advertised price.
     *
     * @param list<array{description:string,quantity?:int,unit_cents:int}> $lines
     * @param array{name?:string,address?:string,city?:string,postal_code?:string,country?:string,vat?:string,email?:string} $customer
     * @param array{mode:string,rate_bp:int,reverse_charge:bool} $treatment
     * @return array<string,mixed>
     */
    public static function issue(
        int $userId,
        ?int $orderId,
        array $lines,
        array $customer,
        array $treatment,
        string $notes = '',
        ?string $dueAt = null,
        ?int $netAlready = null,
    ): array {
        $db = App::db();
        $grossTotal = 0;
        foreach ($lines as $line) {
            $grossTotal += (int) $line['unit_cents'] * (int) ($line['quantity'] ?? 1);
        }
        $rate = (int) $treatment['rate_bp'];
        $tax = $rate > 0 ? Money::vatFromGross($grossTotal, $rate) : 0;
        $netTotal = $grossTotal - $tax;

        $year = (int) gmdate('Y');
        $series = 'A';
        $sequence = \App\Core\Sequences::next('invoice:' . $series . ':' . $year);
        $number = sprintf('%s-%d-%04d', $series, $year, $sequence);
        $invoiceId = $db->insert('invoices', [
            'number' => $number,
            'series' => $series,
            'year' => $year,
            'sequence' => $sequence,
            'user_id' => $userId,
            'order_id' => $orderId,
            'status' => 'pending',
            'currency' => (string) config('app.currency', 'EUR'),
            'subtotal_cents' => $netTotal,
            'discount_cents' => 0,
            'tax_cents' => $tax,
            'total_cents' => $grossTotal,
            'tax_mode' => (string) $treatment['mode'],
            'vat_country' => strtoupper((string) ($customer['country'] ?? 'IT')),
            'vat_rate_bp' => $rate,
            'reverse_charge' => !empty($treatment['reverse_charge']) ? 1 : 0,
            'customer_json' => \App\Core\Json::encode($customer),
            'due_at' => $dueAt ?? gmdate('Y-m-d H:i:s', time() + 7 * 86400),
            'notes' => $notes,
            'created_at' => Database::now(),
        ]);

        $remainingNet = $netTotal;
        $count = count($lines);
        foreach ($lines as $index => $line) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $lineGross = (int) $line['unit_cents'] * $quantity;
            if ($index === $count - 1) {
                $lineNet = $remainingNet;
            } else {
                $lineNet = $rate > 0 ? Money::divRound($lineGross * 10000, 10000 + $rate) : $lineGross;
                $remainingNet -= $lineNet;
            }
            $db->insert('invoice_lines', [
                'invoice_id' => $invoiceId,
                'description' => $line['description'],
                'quantity' => $quantity,
                'unit_cents' => $quantity > 0 ? Money::divRound($lineNet, $quantity) : $lineNet,
                'total_cents' => $lineNet,
                'tax_rate_bp' => $rate,
            ]);
        }

        return self::find($invoiceId) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return App::db()->selectOne('SELECT * FROM invoices WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByNumber(string $number): ?array
    {
        return App::db()->selectOne('SELECT * FROM invoices WHERE number = :number', ['number' => $number]);
    }

    /** @return list<array<string,mixed>> */
    public static function lines(int $invoiceId): array
    {
        return App::db()->select('SELECT * FROM invoice_lines WHERE invoice_id = :id ORDER BY id ASC', ['id' => $invoiceId]);
    }

    public static function markPaid(int $invoiceId, ?string $paidAt = null): void
    {
        App::db()->update('invoices', [
            'status' => 'paid',
            'paid_at' => $paidAt ?? Database::now(),
            'updated_at' => Database::now(),
        ], 'id = :id AND status = :pending', ['id' => $invoiceId, 'pending' => 'pending']);
    }

    public static function markPending(int $invoiceId): void
    {
        App::db()->update('invoices', ['status' => 'pending', 'updated_at' => Database::now()], 'id = :id', ['id' => $invoiceId]);
    }

    /** Renders and stores the PDF, returning the absolute path. */
    public static function pdf(int $invoiceId, bool $force = false): string
    {
        $invoice = self::find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found');
        }
        $directory = App::path('storage/invoices');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $path = $directory . '/' . $invoice['number'] . '.pdf';
        if (is_file($path) && !$force) {
            return $path;
        }

        $lines = self::lines($invoiceId);
        $customer = \App\Core\Json::decode((string) ($invoice['customer_json'] ?? ''));
        $company = self::company();
        $currency = (string) $invoice['currency'];
        $pdf = new PdfDocument(__('invoice') . ' ' . $invoice['number']);
        $left = 48.0;
        $right = 547.0;

        $pdf->rect(0, 0, 595.28, 96, 0.96);
        $pdf->text($left, 38, (string) ($company['name'] ?? config('app.name')), 18, true);
        $pdf->text($left, 58, trim(($company['address'] ?? '') . ' - ' . ($company['city'] ?? ''), ' -'), 9);
        $pdf->text($left, 72, 'P.IVA ' . ($company['vat'] ?? '-') . '  ' . ($company['email'] ?? ''), 9);
        $pdf->text($right - 150, 38, __('invoice'), 20, true);
        $pdf->text($right - 150, 58, 'N. ' . $invoice['number'], 10, true);
        $pdf->text($right - 150, 72, __('issued_on') . ' ' . fmt_date((string) $invoice['created_at'], 'd/m/Y'), 9);

        $y = 130.0;
        $pdf->text($left, $y, __('billed_to'), 9, true);
        $y += 16;
        $pdf->text($left, $y, (string) ($customer['name'] ?? ''), 11, true);
        $y += 14;
        foreach ([$customer['address'] ?? '', trim(((string) ($customer['postal_code'] ?? '')) . ' ' . ((string) ($customer['city'] ?? ''))), (string) ($customer['country'] ?? ''), !empty($customer['vat']) ? 'VAT ' . $customer['vat'] : ''] as $row) {
            if (trim((string) $row) === '') {
                continue;
            }
            $pdf->text($left, $y, (string) $row, 9);
            $y += 12;
        }

        $y = max($y + 12, 215.0);
        $pdf->rect($left, $y - 12, $right - $left, 22, 0.9);
        $pdf->text($left + 6, $y + 2, __('description'), 9, true);
        $pdf->text(360, $y + 2, __('qty'), 9, true);
        $pdf->text(400, $y + 2, __('unit_price'), 9, true);
        $pdf->text(490, $y + 2, __('amount'), 9, true);
        $y += 24;

        foreach ($lines as $line) {
            if ($y > 690) {
                $pdf->addPage();
                $y = 60.0;
            }
            $pdf->text($left + 6, $y, mb_substr((string) $line['description'], 0, 62), 9);
            $pdf->text(360, $y, (string) $line['quantity'], 9);
            $pdf->text(400, $y, Money::format((int) $line['unit_cents'], $currency, false), 9);
            $pdf->text(490, $y, Money::format((int) $line['total_cents'], $currency, false), 9);
            $y += 16;
        }

        $y += 12;
        $pdf->rect($left, $y - 10, $right - $left, 0.6, 0.8);
        $pdf->text(360, $y + 6, __('net_total'), 9);
        $pdf->text(490, $y + 6, Money::format((int) $invoice['subtotal_cents'], $currency, false), 9);
        $y += 16;
        $taxLabel = __('vat') . ' ' . number_format((int) $invoice['vat_rate_bp'] / 100, 2, ',', '') . '%';
        if (!empty($invoice['reverse_charge'])) {
            $taxLabel .= ' (' . __('reverse_charge') . ')';
        }
        $pdf->text(360, $y + 6, $taxLabel, 9);
        $pdf->text(490, $y + 6, Money::format((int) $invoice['tax_cents'], $currency, false), 9);
        $y += 22;
        $pdf->rect($left, $y - 8, $right - $left, 26, 0.9);
        $pdf->text(360, $y + 8, __('total'), 11, true);
        $pdf->text(470, $y + 8, Money::format((int) $invoice['total_cents'], $currency), 11, true);

        $footer = (string) ($company['footer'] ?? '');
        $pdf->text($left, 760, $footer !== '' ? mb_substr($footer, 0, 120) : __('invoice_footer'), 8);
        $pdf->text($left, 774, __('payment_terms'), 8);

        file_put_contents($path, $pdf->output());
        App::db()->update('invoices', ['pdf_path' => 'storage/invoices/' . $invoice['number'] . '.pdf'], 'id = :id', ['id' => $invoiceId]);

        return $path;
    }

    /** @return array<string,string> */
    public static function company(): array
    {
        return [
            'name' => (string) App::setting('company_name', config('app.name')),
            'vat' => (string) App::setting('company_vat', ''),
            'address' => (string) App::setting('company_address', ''),
            'city' => (string) App::setting('company_city', ''),
            'email' => (string) App::setting('company_email', config('mail.from_address')),
            'country' => (string) App::setting('company_country', 'IT'),
            'footer' => (string) App::setting('invoice_footer', ''),
        ];
    }

    public static function creditNote(int $invoiceId, int $amountCents, string $reason): array
    {
        $invoice = self::find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found');
        }
        $year = (int) gmdate('Y');
        $sequence = \App\Core\Sequences::next('credit_note:A:' . $year);
        $number = sprintf('NC-%d-%04d', $year, $sequence);
        $id = App::db()->insert('credit_notes', [
            'number' => $number,
            'invoice_id' => $invoiceId,
            'amount_cents' => $amountCents,
            'reason' => $reason,
            'created_at' => Database::now(),
        ]);
        if ($amountCents >= (int) $invoice['total_cents']) {
            App::db()->update('invoices', ['status' => 'void', 'updated_at' => Database::now()], 'id = :id', ['id' => $invoiceId]);
        }

        return App::db()->selectOne('SELECT * FROM credit_notes WHERE id = :id', ['id' => $id]) ?? [];
    }

}
