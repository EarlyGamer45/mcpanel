<?php

declare(strict_types=1);

namespace App\Core;

/**
 * All amounts are integers in minor units (cents). No float ever touches a
 * price, a tax amount or a wallet balance: the arithmetic below is pure integer
 * with half-up rounding.
 */
final class Money
{
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF'];

    public static function format(int $cents, string $currency = 'EUR', bool $withSymbol = true): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $units = intdiv($absolute, 100);
        $fraction = $absolute % 100;
        $formatted = number_format($units, 0, ',', '.') . ',' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
        $symbol = self::SYMBOLS[strtoupper($currency)] ?? strtoupper($currency);
        $sign = $negative ? '-' : '';

        return $withSymbol ? $sign . $formatted . ' ' . $symbol : $sign . $formatted;
    }

    /** Round half up without floats. */
    public static function divRound(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new \InvalidArgumentException('Division by zero');
        }
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
        $numerator = abs($numerator);
        $denominator = abs($denominator);
        $result = intdiv($numerator * 2 + $denominator, $denominator * 2);

        return $sign * $result;
    }

    /** Applies a basis-point rate (2200 bp = 22%) to an amount. */
    public static function applyRate(int $cents, int $rateBp): int
    {
        return self::divRound($cents * $rateBp, 10000);
    }

    /** Included VAT inside a gross amount. */
    public static function vatFromGross(int $grossCents, int $rateBp): int
    {
        if ($rateBp === 0) {
            return 0;
        }

        return self::divRound($grossCents * $rateBp, 10000 + $rateBp);
    }

    public static function netFromGross(int $grossCents, int $rateBp): int
    {
        return $grossCents - self::vatFromGross($grossCents, $rateBp);
    }

    public static function grossFromNet(int $netCents, int $rateBp): int
    {
        return $netCents + self::applyRate($netCents, $rateBp);
    }

    /** Prorated amount for the remaining days of a billing period. */
    public static function prorata(int $cents, int $remainingDays, int $periodDays): int
    {
        if ($periodDays <= 0 || $remainingDays <= 0) {
            return 0;
        }
        $remainingDays = min($remainingDays, $periodDays);

        return self::divRound($cents * $remainingDays, $periodDays);
    }

    /** Length in days of a billing cycle, anchored on a start date. */
    public static function cycleDays(string $cycle, ?\DateTimeImmutable $from = null): int
    {
        $from ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return match ($cycle) {
            'monthly' => 30,
            'bimonthly' => 60,
            'semestral' => 182,
            'annual' => 365,
            'quarterly' => 91,
            'weekly' => 7,
            default => 30,
        };
    }

    public static function cycleEnd(string $cycle, \DateTimeImmutable $start): \DateTimeImmutable
    {
        return $start->modify('+' . match ($cycle) {
            'monthly' => '1 month',
            'bimonthly' => '2 months',
            'quarterly' => '3 months',
            'semestral' => '6 months',
            'annual' => '1 year',
            'weekly' => '1 week',
            default => '1 month',
        });
    }

    /** @return list<string> */
    public static function cycles(): array
    {
        return ['monthly', 'bimonthly', 'semestral', 'annual'];
    }

    /**
     * Parses an amount typed in euros into cents, without floats.
     * Accepts "12", "12,5", "12,50", "12.50", "1.234,56", "1,234.56", "€ 9,99";
     * refuses ambiguous input such as "12,345".
     * Returns null when the text is not a valid non-negative amount.
     */
    public static function parseInput(string $value): ?int
    {
        $value = str_replace(['€', ' ', "\u{00A0}", "'"], '', trim($value));
        if ($value === '') {
            return null;
        }
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        $decimalPos = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);
        $units = $value;
        $fraction = '';
        if ($decimalPos >= 0) {
            $tail = substr($value, $decimalPos + 1);
            // "12,345" could be a typo or a thousands group: refuse it rather
            // than guess and save a price a thousand times too high.
            $separators = substr_count($value, ',') + substr_count($value, '.');
            if (strlen($tail) === 3 && $separators === 1) {
                return null;
            }
            $units = substr($value, 0, $decimalPos);
            $fraction = $tail;
        }
        $units = str_replace([',', '.'], '', $units);
        if ($units === '') {
            $units = '0';
        }
        if (!ctype_digit($units) || ($fraction !== '' && (!ctype_digit($fraction) || strlen($fraction) > 2))) {
            return null;
        }
        if (strlen($units) > 12) {
            return null;
        }

        return (int) $units * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** Formats cents for an input field: 1250 → "12,50" (no symbol, no grouping). */
    public static function toInput(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

/**
 * VAT rules: domestic, EU B2B reverse charge and OSS destination rate. Rates
 * come from the tax_rates-like configuration in Settings (basis points) so the
 * platform can serve several countries without a deployment.
 */
final class Vat
{
    /** @var array<string,int> default EU consumer rates, basis points */
    public const DEFAULT_RATES = [
        'IT' => 2200, 'DE' => 1900, 'FR' => 2000, 'ES' => 2100, 'NL' => 2100,
        'BE' => 2100, 'AT' => 2000, 'PT' => 2300, 'IE' => 2300, 'PL' => 2300,
        'SE' => 2500, 'DK' => 2500, 'FI' => 2550, 'GR' => 2400, 'CZ' => 2100,
        'RO' => 2100, 'HU' => 2700, 'SK' => 2300, 'SI' => 2200, 'HR' => 2500,
        'BG' => 2000, 'EE' => 2200, 'LV' => 2100, 'LT' => 2100, 'LU' => 1700,
        'MT' => 1800, 'CY' => 1900, 'GB' => 2000, 'CH' => 810, 'US' => 0,
    ];

    public const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    public static function isEu(string $country): bool
    {
        return in_array(strtoupper($country), self::EU, true);
    }

    public static function rateFor(string $country): int
    {
        return self::DEFAULT_RATES[strtoupper($country)] ?? 2200;
    }

    /**
     * Determines the tax treatment for an invoice.
     *
     * @return array{mode:string,rate_bp:int,reverse_charge:bool}
     */
    public static function treatment(string $sellerCountry, string $customerCountry, bool $isBusiness, bool $viesValid): array
    {
        $seller = strtoupper($sellerCountry);
        $customer = strtoupper($customerCountry);

        if ($customer === $seller) {
            return ['mode' => 'domestic', 'rate_bp' => self::rateFor($customer), 'reverse_charge' => false];
        }
        if (self::isEu($customer) && $isBusiness && $viesValid) {
            return ['mode' => 'reverse_charge', 'rate_bp' => 0, 'reverse_charge' => true];
        }
        if (self::isEu($customer)) {
            // OSS: consumer pays the VAT rate of their country of residence.
            return ['mode' => 'oss', 'rate_bp' => self::rateFor($customer), 'reverse_charge' => false];
        }

        return ['mode' => 'export', 'rate_bp' => 0, 'reverse_charge' => false];
    }
}
