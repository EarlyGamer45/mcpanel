<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Json;
use App\Core\Money;

final class Catalog
{
    /** @return list<array<string,mixed>> */
    public static function categories(): array
    {
        return App::db()->select('SELECT * FROM categories WHERE visible = 1 ORDER BY sort ASC, id ASC');
    }

    /** @return list<array<string,mixed>> */
    public static function products(?int $categoryId = null, bool $includeHidden = false): array
    {
        $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug
                FROM products p JOIN categories c ON c.id = p.category_id
                WHERE ' . ($includeHidden ? '1 = 1' : 'p.visible = 1');
        $bind = [];
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = :category';
            $bind['category'] = $categoryId;
        }
        $sql .= ' ORDER BY p.sort ASC, p.id ASC';
        $products = App::db()->select($sql, $bind);
        foreach ($products as &$product) {
            $product['prices'] = self::prices((int) $product['id']);
            $product['from_cents'] = (int) ($product['prices']['monthly']['price_cents'] ?? 0);
            $product['plan_type'] = self::planType($product);
            if ($product['plan_type'] === 'fixed') {
                $product['fixed'] = self::fixedSpecs($product);
            } else {
                // "From" price of a configurator = the cheapest configuration.
                $product['options'] = self::resourceOptions((int) $product['id']);
                $product['addons'] = [];
                $minimum = PriceCalculator::quote($product, [], [], 'monthly');
                $product['from_cents'] = (int) $minimum['subtotal_cents'];
            }
        }

        return $products;
    }

    /** @return array<string,mixed>|null */
    public static function product(string $slug): ?array
    {
        $product = App::db()->selectOne(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM products p JOIN categories c ON c.id = p.category_id
             WHERE p.slug = :slug LIMIT 1',
            ['slug' => $slug]
        );
        if ($product === null) {
            return null;
        }
        $product['prices'] = self::prices((int) $product['id']);
        $product['options'] = self::resourceOptions((int) $product['id']);
        $product['addons'] = self::addons((int) $product['id']);
        $product['versions'] = self::versions($product);
        $product['plan_type'] = self::planType($product);

        return $product;
    }

    /** @return array<string,mixed>|null */
    public static function productById(int $id): ?array
    {
        $product = App::db()->selectOne('SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id = :id', ['id' => $id]);
        if ($product === null) {
            return null;
        }
        $product['prices'] = self::prices($id);
        $product['options'] = self::resourceOptions($id);
        $product['configured_options'] = self::configuredOptions($id);
        $product['addons'] = self::addons($id);
        $product['versions'] = self::versions($product);
        $product['plan_type'] = self::planType($product);

        return $product;
    }

    /** @return array<string,array<string,mixed>> cycle => row */
    public static function prices(int $productId): array
    {
        $rows = App::db()->select('SELECT * FROM product_prices WHERE product_id = :id', ['id' => $productId]);
        $prices = [];
        foreach ($rows as $row) {
            $prices[(string) $row['cycle']] = $row;
        }

        return $prices;
    }

    public const PLAN_TYPES = ['fixed', 'custom'];

    /** First visible configurable product (the "build your own server" page), if any. */
    public static function firstConfigurator(): ?array
    {
        foreach (self::products() as $product) {
            if ($product['plan_type'] === 'custom') {
                return $product;
            }
        }

        return null;
    }

    /** Visible add-ons offered on at least one visible product, resource packs first. */
    public static function expansionPacks(): array
    {
        $rows = App::db()->select(
            'SELECT DISTINCT a.* FROM addons a
             JOIN product_addons pa ON pa.addon_id = a.id AND pa.enabled = 1
             JOIN products p ON p.id = pa.product_id AND p.visible = 1
             WHERE a.visible = 1 ORDER BY a.id ASC'
        );
        usort($rows, static fn (array $a, array $b): int => (PriceCalculator::addonEffect($a) === null) <=> (PriceCalculator::addonEffect($b) === null));

        return $rows;
    }

    /** How the catalogue shows the two kinds of plans (Admin → Products → Catalogue display). */
    public static function displaySettings(): array
    {
        $order = (string) App::setting('catalog_order', 'fixed_first');

        return [
            'show_fixed' => App::setting('catalog_show_fixed', true) !== false,
            'show_custom' => App::setting('catalog_show_custom', true) !== false,
            'show_packs' => App::setting('catalog_show_packs', true) !== false,
            'packs_title' => self::settingText('catalog_packs_title', __('catalog_packs_title_default')),
            'packs_lead' => self::settingText('catalog_packs_lead', __('catalog_packs_lead_default')),
            'order' => $order === 'custom_first' ? 'custom_first' : 'fixed_first',
            'fixed_title' => self::settingText('catalog_fixed_title', __('catalog_fixed_title_default')),
            'fixed_lead' => self::settingText('catalog_fixed_lead', __('catalog_fixed_lead_default')),
            'custom_title' => self::settingText('catalog_custom_title', __('catalog_custom_title_default')),
            'custom_lead' => self::settingText('catalog_custom_lead', __('catalog_custom_lead_default')),
        ];
    }

    private static function settingText(string $key, string $default): string
    {
        $value = trim((string) App::setting($key, ''));

        return $value !== '' ? $value : $default;
    }

    /** "fixed" = ready-made plan with set resources and price; "custom" = configurator with sliders. */
    public static function planType(array $product): string
    {
        $meta = Json::decode($product['meta_json'] ?? null);

        return ($meta['plan_type'] ?? 'custom') === 'fixed' ? 'fixed' : 'custom';
    }

    /** @return array{ram_mb:int,cpu_percent:int,disk_mb:int} resources of a fixed plan */
    public static function fixedSpecs(array $product): array
    {
        $meta = Json::decode($product['meta_json'] ?? null);
        $fixed = is_array($meta['fixed'] ?? null) ? $meta['fixed'] : [];

        return [
            'ram_mb' => max(128, (int) ($fixed['ram_mb'] ?? $product['min_ram_mb'] ?? 2048)),
            'cpu_percent' => max(10, (int) ($fixed['cpu_percent'] ?? 100)),
            'disk_mb' => max(1024, (int) ($fixed['disk_mb'] ?? 10240)),
        ];
    }

    /**
     * Resource options for a product. For a fixed plan every resource is
     * pinned to its set value and costs nothing extra, so the quote is the
     * plan price whatever the request contains.
     *
     * @return array<string,array<string,mixed>> resource => option row (product override first)
     */
    public static function resourceOptions(int $productId): array
    {
        $options = self::configuredOptions($productId);
        $product = App::db()->selectOne('SELECT id, min_ram_mb, meta_json FROM products WHERE id = :id', ['id' => $productId]);
        if ($product !== null && self::planType($product) === 'fixed') {
            $specs = self::fixedSpecs($product);
            foreach (PriceCalculator::RESOURCES as $key => $meta) {
                $value = (int) $specs[$meta['column']];
                $options[$key] = ($options[$key] ?? ['resource' => $key, 'unit' => $meta['unit'], 'sort' => 0]) + [];
                $options[$key]['min_value'] = $value;
                $options[$key]['max_value'] = $value;
                $options[$key]['included'] = $value;
                $options[$key]['step'] = max(1, $value);
                $options[$key]['unit_cents'] = 0;
                $options[$key]['fixed'] = true;
            }
        }

        return $options;
    }

    /** @return array<string,array<string,mixed>> configurator options, ignoring the plan type */
    public static function configuredOptions(int $productId): array
    {
        $rows = App::db()->select(
            'SELECT * FROM resource_options WHERE product_id = :id OR product_id IS NULL ORDER BY product_id IS NULL ASC, sort ASC',
            ['id' => $productId]
        );
        $options = [];
        foreach ($rows as $row) {
            $resource = (string) $row['resource'];
            if (!isset($options[$resource])) {
                $options[$resource] = $row;
            }
        }

        return $options;
    }

    /** @return list<array<string,mixed>> */
    public static function addons(int $productId): array
    {
        return App::db()->select(
            'SELECT a.* FROM addons a JOIN product_addons pa ON pa.addon_id = a.id
             WHERE pa.product_id = :id AND pa.enabled = 1 AND a.visible = 1 ORDER BY a.id ASC',
            ['id' => $productId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function locations(): array
    {
        return App::db()->select('SELECT * FROM locations WHERE visible = 1 AND maintenance = 0 ORDER BY sort ASC, id ASC');
    }

    /** @return list<string> */
    public static function versions(array $product): array
    {
        $versions = Json::decode($product['allowed_versions_json'] ?? null);
        if ($versions === []) {
            return ['1.21.8', '1.21.4', '1.20.6', '1.20.1', '1.19.4', '1.18.2', '1.16.5', '1.12.2', '1.8.9'];
        }

        return array_values(array_map('strval', $versions));
    }

    public static function softwareFor(array $product): string
    {
        $meta = Json::decode($product['meta_json'] ?? null);

        return (string) ($meta['software'] ?? 'paper');
    }
}

/**
 * Configurator quote. Everything is integer cents; the cycle price configures
 * the discount and extras are scaled with the same ratio.
 */
final class PriceCalculator
{
    public const RESOURCES = [
        'ram' => ['column' => 'ram_mb', 'label' => 'RAM', 'unit' => 'MB'],
        'cpu' => ['column' => 'cpu_percent', 'label' => 'CPU', 'unit' => '%'],
        'disk' => ['column' => 'disk_mb', 'label' => 'Disk', 'unit' => 'MB'],
    ];

    public static function months(string $cycle): int
    {
        return match ($cycle) {
            'monthly' => 1,
            'bimonthly' => 2,
            'quarterly' => 3,
            'semestral' => 6,
            'annual' => 12,
            'weekly' => 1,
            default => 1,
        };
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,int|string> $selection ram_mb, cpu_percent, disk_mb, version, software, location_id
     * @param list<int|string> $addonIds
     * @return array<string,mixed>
     */
    public static function quote(array $product, array $selection, array $addonIds, string $cycle, ?string $couponCode = null, ?array $user = null): array
    {
        $prices = $product['prices'] ?? Catalog::prices((int) $product['id']);
        $options = $product['options'] ?? Catalog::resourceOptions((int) $product['id']);
        $addons = $product['addons'] ?? Catalog::addons((int) $product['id']);

        $monthlyBase = (int) ($prices['monthly']['price_cents'] ?? 0);
        $cycleBase = (int) ($prices[$cycle]['price_cents'] ?? 0);
        if ($cycleBase <= 0) {
            $cycleBase = $monthlyBase * self::months($cycle);
        }

        $normalized = [];
        $lines = [];
        $extrasMonthly = 0;
        foreach (self::RESOURCES as $key => $meta) {
            $option = $options[$key] ?? null;
            if ($option === null) {
                continue;
            }
            $value = self::clamp(
                (int) ($selection[$meta['column']] ?? ($product['min_' . $meta['column']] ?? $option['min_value'])),
                (int) $option['min_value'],
                (int) $option['max_value'],
                (int) $option['step']
            );
            $included = (int) $option['included'];
            $step = max(1, (int) $option['step']);
            $extraSteps = (int) max(0, (int) ceil(max(0, $value - $included) / $step));
            $amount = $extraSteps * (int) $option['unit_cents'];
            $extrasMonthly += $amount;
            $normalized[$meta['column']] = $value;
            $lines[] = [
                'resource' => $key,
                'label' => $meta['label'] . ' ' . self::humanResource($key, $value),
                'base' => $included,
                'value' => $value,
                'amount_cents' => self::scale($amount, $monthlyBase, $cycleBase, self::months($cycle)),
            ];
        }

        $normalized['version'] = (string) ($selection['version'] ?? (Catalog::versions($product)[0] ?? '1.21.8'));
        $normalized['software'] = (string) ($selection['software'] ?? Catalog::softwareFor($product));
        $normalized['location_id'] = isset($selection['location_id']) ? (int) $selection['location_id'] : null;

        $addonLines = [];
        $addonsMonthly = 0;
        $selectedAddons = [];
        foreach ($addons as $addon) {
            if (!in_array((int) $addon['id'], array_map('intval', $addonIds), true)) {
                continue;
            }
            $amount = (int) $addon['price_cents'];
            $addonsMonthly += $amount;
            $selectedAddons[] = (int) $addon['id'];
            $addonLines[] = [
                'id' => (int) $addon['id'],
                'name' => (string) $addon['name'],
                'amount_cents' => self::scale($amount, $monthlyBase, $cycleBase, self::months($cycle)),
            ];
        }

        // Expansion packs that add resources (+RAM, +vCPU, +SSD) raise the server limits.
        foreach ($addons as $addon) {
            if (!in_array((int) $addon['id'], $selectedAddons, true)) {
                continue;
            }
            $effect = self::addonEffect($addon);
            if ($effect !== null) {
                $column = self::RESOURCES[$effect['resource']]['column'];
                $normalized[$column] = (int) ($normalized[$column] ?? 0) + $effect['amount'];
            }
        }

        $subtotal = $cycleBase;
        foreach ($lines as $line) {
            $subtotal += $line['amount_cents'];
        }
        foreach ($addonLines as $line) {
            $subtotal += $line['amount_cents'];
        }

        $coupon = null;
        $discount = 0;
        if ($couponCode !== null && $couponCode !== '') {
            $coupon = Coupons::validate($couponCode, $subtotal, (int) ($product['id'] ?? 0), $user);
            if ($coupon !== null) {
                $discount = Coupons::discount($coupon, $subtotal);
            }
        }

        return [
            'cycle' => $cycle,
            'months' => self::months($cycle),
            'base_cents' => $cycleBase,
            'resources' => $lines,
            'addons' => $addonLines,
            'addon_ids' => $selectedAddons,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'coupon' => $coupon,
            'total_cents' => max(0, $subtotal - $discount),
            'config' => $normalized,
        ];
    }

    public static function clamp(int $value, int $min, int $max, int $step): int
    {
        $value = max($min, min($max, $value));
        if ($step > 1) {
            $steps = (int) round(($value - $min) / $step);
            $value = $min + $steps * $step;
        }

        return max($min, min($max, $value));
    }

    public static function humanResource(string $resource, int $value): string
    {
        return match ($resource) {
            'ram', 'disk' => $value >= 1024 && $value % 1024 === 0 ? (int) ($value / 1024) . ' GB' : $value . ' MB',
            'cpu' => self::cores($value) . ' vCPU',
            default => (string) $value,
        };
    }

    /**
     * Resource added by an expansion pack, from its meta_json
     * ({"resource":"ram","amount":2048}), or null for a plain add-on.
     *
     * @return array{resource:string,amount:int}|null
     */
    public static function addonEffect(array $addon): ?array
    {
        $meta = Json::decode($addon['meta_json'] ?? null);
        $resource = (string) ($meta['resource'] ?? '');
        $amount = (int) ($meta['amount'] ?? 0);
        if (!isset(self::RESOURCES[$resource]) || $amount <= 0) {
            return null;
        }

        return ['resource' => $resource, 'amount' => $amount];
    }

    /** Short label of an expansion pack effect: "+2 GB RAM", "+1 vCPU", "+10 GB SSD". */
    public static function addonEffectLabel(array $addon): string
    {
        $effect = self::addonEffect($addon);
        if ($effect === null) {
            return '';
        }

        return match ($effect['resource']) {
            'ram' => '+' . self::humanResource('ram', $effect['amount']) . ' RAM',
            'cpu' => '+' . self::cores($effect['amount']) . ' vCPU',
            default => '+' . self::humanResource('disk', $effect['amount']) . ' SSD',
        };
    }

    /** CPU percent as vCPU cores: 100 → "1", 150 → "1,5". */
    public static function cores(int $percent): string
    {
        $cores = $percent / 100;

        return rtrim(rtrim(number_format($cores, 2, ',', ''), '0'), ',');
    }

    /** Scales a monthly extra with the cycle discount ratio. */
    private static function scale(int $monthlyCents, int $monthlyBase, int $cycleBase, int $months = 1): int
    {
        if ($monthlyCents === 0) {
            return 0;
        }
        if ($monthlyBase <= 0) {
            // No base price (pure configurator): extras are simply paid for every month of the cycle.
            return $monthlyCents * max(1, $months);
        }

        return Money::divRound($monthlyCents * $cycleBase, $monthlyBase);
    }
}

final class PlanRecommender
{
    /**
     * Configurable rules: players => minimum RAM, plus a modpack multiplier.
     *
     * @return array{ram_mb:int,product:?array<string,mixed>,reason:string}
     */
    public static function suggest(int $players, string $type, array $products, int $modpackOverhead = 2048): array
    {
        $players = max(1, $players);
        $ram = 1024 + $players * 512;
        if ($type === 'modpack') {
            $ram += $modpackOverhead;
        } elseif ($type === 'plugins') {
            $ram += 1024;
        }
        $ram = (int) (ceil($ram / 1024) * 1024);

        $best = null;
        foreach ($products as $product) {
            $min = (int) ($product['min_ram_mb'] ?? 0);
            $max = (int) ($product['max_ram_mb'] ?? 0);
            if ($ram >= $min && $ram <= $max) {
                $best = $product;
                break;
            }
        }
        if ($best === null && $products !== []) {
            $best = $products[count($products) - 1];
        }

        return [
            'ram_mb' => $ram,
            'product' => $best,
            'reason' => match ($type) {
                'modpack' => 'Modpack server: extra heap for mods and world generation.',
                'plugins' => 'Plugin server: room for plugins and players.',
                default => 'Vanilla server sized on player count.',
            },
        ];
    }
}
