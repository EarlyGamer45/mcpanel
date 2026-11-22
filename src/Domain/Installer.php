<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Json;
use App\Core\Sequences;

/**
 * First-run defaults and optional demo data. Both are idempotent: running them
 * twice never duplicates the catalog.
 */
final class Installer
{
    public static function defaults(): void
    {
        $db = App::db();
        self::settings();
        self::featureFlags();
        self::legalDocuments();
        self::knowledgeBase();
        self::catalog();
    }

    public static function admin(string $username, string $email, string $password): array
    {
        $user = UserRepository::create($username, $email, $password, ['country' => 'IT']);
        App::db()->update('users', ['role' => 'super_admin', 'email_verified_at' => Database::now()], 'id = :id', ['id' => (int) $user['id']]);
        $full = UserRepository::find((int) $user['id']) ?? $user;
        AuditSeed::log('install.admin_created', (int) $user['id']);

        return $full;
    }

    public static function demos(): void
    {
        $db = App::db();
        if ((int) $db->value('SELECT COUNT(*) FROM services') > 0) {
            return;
        }
        $demoEmail = 'demo@example.com';
        $user = UserRepository::findByEmail($demoEmail);
        if ($user === null) {
            $user = UserRepository::create('demoplayer', $demoEmail, 'Demo12345!', [
                'country' => 'IT',
                'company' => 'Demo Craft Ltd',
                'vat' => 'IT12345678901',
                'address' => 'Via Roma 1',
                'city' => 'Milano',
                'postal_code' => '20100',
                'phone' => '+39 02 1234567',
            ]);
            $db->update('users', ['email_verified_at' => Database::now()], 'id = :id', ['id' => (int) $user['id']]);
            WalletService::credit((int) $user['id'], 2500, 'demo', 'seed');
        }
        $product = $db->selectOne('SELECT * FROM products WHERE slug = :slug', ['slug' => 'package-8gb'])
            ?? $db->selectOne('SELECT * FROM products ORDER BY id ASC LIMIT 1');
        if ($product === null) {
            return;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ([['Emerald MC', 8192, 200, 20480, 'active', '+1 month'], ['Netherite MC', 16384, 400, 51200, 'suspended', '+12 days']] as $index => [$name, $ram, $cpu, $disk, $status, $due]) {
            $serviceId = $db->insert('services', [
                'user_id' => (int) $user['id'],
                'product_id' => (int) $product['id'],
                'name' => $name . ' · Paper 1.21.8',
                'status' => $status,
                'cycle' => 'monthly',
                'price_cents' => 1999 + $index * 1000,
                'currency' => 'EUR',
                'ram_mb' => $ram,
                'cpu_percent' => $cpu,
                'disk_mb' => $disk,
                'location_id' => (int) ($db->value('SELECT id FROM locations ORDER BY id ASC LIMIT 1') ?? 0) ?: null,
                'config_json' => Json::encode(['ram_mb' => $ram, 'cpu_percent' => $cpu, 'disk_mb' => $disk, 'version' => '1.21.8', 'software' => 'paper']),
                'provision_key' => hash('sha256', 'demo-service:' . $index),
                'provisioning_state' => 'ready',
                'ip' => '203.0.113.' . (10 + $index),
                'port' => 25565 + $index,
                'next_due_at' => (new \DateTimeImmutable($due, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'auto_renew' => 1,
                'created_at' => Database::now(),
            ]);
            Services::recordEvent($serviceId, null, $status, 'demo seed');
            if ($status === 'suspended') {
                $db->update('services', ['suspended_at' => $now->modify('-5 days')->format('Y-m-d H:i:s')], 'id = :id', ['id' => $serviceId]);
            }
        }

        $order = App::db()->transaction(static function (Database $db) use ($user, $product): array {
            $total = 1999;
            $year = (int) gmdate('Y');
            $number = sprintf('ORD-%d-%05d', $year, Sequences::next('order:' . $year));
            $orderId = $db->insert('orders', [
                'number' => $number, 'user_id' => (int) $user['id'], 'status' => 'paid', 'currency' => 'EUR',
                'subtotal_cents' => 1639, 'discount_cents' => 0, 'tax_cents' => 360, 'total_cents' => $total,
                'paid_at' => Database::now(), 'created_at' => Database::now(),
            ]);
            $db->insert('order_items', [
                'order_id' => $orderId, 'product_id' => (int) $product['id'], 'description' => 'Emerald MC · Paper 1.21.8',
                'cycle' => 'monthly', 'unit_cents' => $total, 'quantity' => 1, 'total_cents' => $total,
            ]);
            $invoice = InvoiceService::issue((int) $user['id'], $orderId, [
                ['description' => 'Emerald MC · Paper 1.21.8', 'quantity' => 1, 'unit_cents' => $total],
            ], [
                'name' => 'Demo Craft Ltd', 'address' => '1 Example Street', 'city' => 'Milan', 'postal_code' => '20100',
                'country' => 'IT', 'vat' => 'IT12345678901', 'email' => 'demo@example.com',
            ], ['mode' => 'domestic', 'rate_bp' => 2200, 'reverse_charge' => false], 'Demo order');
            InvoiceService::markPaid((int) $invoice['id']);
            $db->update('orders', ['invoice_id' => (int) $invoice['id']], 'id = :id', ['id' => $orderId]);

            return $db->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]) ?? [];
        });

        $ticketId = $db->insert('tickets', [
            'number' => sprintf('T-%d-%05d', (int) gmdate('Y'), Sequences::next('ticket:' . (int) gmdate('Y'))),
            'user_id' => (int) $user['id'],
            'department' => 'technical',
            'priority' => 'normal',
            'status' => 'open',
            'subject' => 'How do I install a modpack?',
            'created_at' => Database::now(),
            'updated_at' => Database::now(),
        ]);
        $db->insert('ticket_messages', [
            'ticket_id' => $ticketId, 'user_id' => (int) $user['id'],
            'body' => 'Hi, I would like to install a modpack on Emerald MC. How do I do it?',
            'internal' => 0, 'created_at' => Database::now(),
        ]);
    }

    public static function settings(): void
    {
        $defaults = [
            'brand_name' => 'MCPanel Hosting',
            'brand_color' => '#22c55e',
            'company_name' => 'Your Company Ltd',
            'company_vat' => '',
            'company_address' => '1 Example Street',
            'company_city' => 'Your City',
            'company_country' => 'IT',
            'company_email' => 'billing@example.com',
            'invoice_footer' => 'This document was generated electronically.',
            'renew_notice_days' => 7,
            'grace_days' => 3,
            'terminate_after_days' => 14,
            'risk_review_threshold' => 70,
            'auto_charge_wallet' => true,
            'enforce_admin_2fa' => false,
            'maintenance_mode' => false,
            'log_retention_days' => 90,
            'legal_version' => '2026-01',
            'totp_required_admins' => false,
        ];
        foreach ($defaults as $name => $value) {
            if (App::db()->selectOne('SELECT id FROM settings WHERE name = :name', ['name' => $name]) === null) {
                App::setSetting($name, $value);
            }
        }
    }

    public static function featureFlags(): void
    {
        $flags = [
            'tickets' => true, 'wallet' => true, 'stripe' => true, 'paypal' => true, 'bank' => true,
            'coupons' => true, 'kb' => true, 'status_page' => true, 'discord_login' => false,
            'referrals' => false, 'loyalty' => false, 'gift_cards' => false, 'api' => true,
            'resource_pools' => false, 'subdomains' => false, 'e_invoicing' => false, 'pwa' => false,
        ];
        foreach ($flags as $name => $enabled) {
            if (App::db()->selectOne('SELECT id FROM feature_flags WHERE name = :name', ['name' => $name]) === null) {
                App::db()->insert('feature_flags', ['name' => $name, 'enabled' => $enabled ? 1 : 0, 'description' => null]);
            }
            App::setSetting('gateway_enabled.' . $name, true);
        }
    }

    public static function legalDocuments(): void
    {
        $documents = [
            'terms' => ['Terms of Service', 'terms_body'],
            'privacy' => ['Privacy Policy', 'privacy_body'],
            'cookies' => ['Cookie policy', 'cookies_body'],
            'aup' => ['Acceptable Use Policy', 'aup_body'],
            'refunds' => ['Refund Policy', 'refunds_body'],
            'sla' => ['Service Level Agreement', 'sla_body'],
        ];
        foreach ($documents as $name => [$title, $key]) {
            if (App::db()->selectOne('SELECT id FROM legal_documents WHERE name = :name', ['name' => $name]) === null) {
                App::db()->insert('legal_documents', [
                    'name' => $name,
                    'version' => '2026-01',
                    'body' => '<h2>' . $title . '</h2><p>' . __('legal_placeholder') . '</p><p>' . __('legal_placeholder_2') . '</p>',
                    'updated_at' => Database::now(),
                ]);
            }
        }
    }

    public static function knowledgeBase(): void
    {
        if ((int) App::db()->value('SELECT COUNT(*) FROM kb_articles') > 0) {
            return;
        }
        $articles = [
            ['Getting started', 'Starting your first server', 'how-to-start', '<h3>1. Open the panel</h3><p>From the dashboard open the service and use the Start / Stop / Restart buttons.</p><h3>2. Pick the version</h3><p>You can change software and version from the service page: a backup is taken before every change.</p><h3>3. Connect</h3><p>Use the IP address and port shown on the server page.</p>'],
            ['Modpacks', 'Installing a modpack', 'install-modpack', '<p>On the service page open <strong>Modpack</strong> and search CurseForge or Modrinth. If the plan does not have enough RAM, an upgrade is suggested.</p>'],
            ['Billing', 'Invoices and renewals', 'invoices-renewals', '<p>Invoices are available as PDF in the client area. Automatic renewal can be turned off at any time from the service page.</p>'],
        ];
        foreach ($articles as [$category, $title, $slug, $body]) {
            App::db()->insert('kb_articles', [
                'category' => $category,
                'title' => $title,
                'slug' => $slug,
                'body' => $body,
                'locale' => 'en',
                'published' => 1,
            ]);
        }
    }

    /**
     * Ready-made packages, the "build your own" configurator and the expansion
     * packs. Safe to run again: only what is missing is added.
     *
     * @return array{products:int,packs:int,hidden:int}
     */
    public static function packages(bool $hideOthers = false): array
    {
        $db = App::db();
        $category = $db->selectOne("SELECT id FROM categories WHERE slug = 'minecraft-java'")
            ?? $db->selectOne('SELECT id FROM categories ORDER BY sort ASC, id ASC LIMIT 1');
        $categoryId = $category !== null
            ? (int) $category['id']
            : $db->insert('categories', ['name' => 'Minecraft', 'slug' => 'minecraft', 'description' => 'Minecraft servers.', 'sort' => 0, 'visible' => 1]);

        $packs = [
            ['pack_ram_2gb', '+2 GB RAM', 'Adds 2 GB of RAM to the server.', 300, 'ram', 2048],
            ['pack_cpu_1', '+1 vCPU', 'Adds one virtual core to the server.', 250, 'cpu', 100],
            ['pack_ssd_10gb', '+10 GB SSD', 'Adds 10 GB of SSD space.', 100, 'disk', 10240],
            ['pack_cpu_performance', 'CPU Performance', 'Two extra cores for servers with many plugins or mods.', 450, 'cpu', 200],
            ['pack_backup_daily', 'Daily backups', 'One automatic backup per day, kept for 7 days.', 200, 'backup', 0],
        ];
        $packsAdded = 0;
        foreach ($packs as [$code, $name, $description, $price, $resource, $amount]) {
            if ($db->selectOne('SELECT id FROM addons WHERE code = :c', ['c' => $code]) !== null) {
                continue;
            }
            $meta = in_array($resource, ['ram', 'cpu', 'disk'], true) ? ['resource' => $resource, 'amount' => $amount] : [];
            $db->insert('addons', [
                'code' => $code, 'name' => $name, 'description' => $description, 'price_cents' => $price,
                'cycle' => 'monthly', 'type' => $resource, 'meta_json' => Json::encode($meta), 'visible' => 1,
            ]);
            $packsAdded++;
        }

        // slug, name, RAM MB, CPU %, SSD MB, monthly cents, tier, featured, description
        $fixed = [
            ['package-2gb', 'Package 2 GB', 2048, 100, 5120, 400, 'budget', false, 'For playing with a few friends.'],
            ['package-4gb', 'Package 4 GB', 4096, 200, 5120, 800, 'standard', false, 'For a small community with a few plugins.'],
            ['package-8gb', 'Package 8 GB', 8192, 400, 10240, 1400, 'standard', true, 'Most popular: plugins, big maps and 20-40 players.'],
            ['package-12gb', 'Package 12 GB', 12288, 600, 15360, 2000, 'premium', false, 'For modpacks and busy servers.'],
            ['package-16gb', 'Package 16 GB', 16384, 800, 20480, 2600, 'premium', false, 'For server networks and heavy modpacks.'],
        ];
        $versions = Json::encode(['1.21.8', '1.21.4', '1.20.6', '1.20.1', '1.19.4', '1.18.2', '1.16.5', '1.12.2', '1.8.9']);
        $created = [];
        $sort = (int) $db->value('SELECT COALESCE(MIN(sort), 0) FROM products') - 20;
        foreach ($fixed as $index => [$slug, $name, $ram, $cpu, $disk, $price, $tier, $featured, $description]) {
            if (self::productExists($slug)) {
                continue;
            }
            $id = $db->insert('products', [
                'category_id' => $categoryId, 'name' => $name, 'slug' => $slug, 'description' => $description,
                'tier' => $tier, 'sort' => $sort + $index, 'visible' => 1,
                'min_ram_mb' => $ram, 'max_ram_mb' => $ram, 'ram_step_mb' => 1024, 'allowed_versions_json' => $versions,
                'meta_json' => Json::encode([
                    'software' => 'paper', 'plan_type' => 'fixed', 'featured' => $featured,
                    'fixed' => ['ram_mb' => $ram, 'cpu_percent' => $cpu, 'disk_mb' => $disk],
                ]),
                'created_at' => Database::now(),
            ]);
            foreach (['monthly' => 1, 'bimonthly' => 1.95, 'semestral' => 5.7, 'annual' => 10.8] as $cycle => $factor) {
                $db->insert('product_prices', ['product_id' => $id, 'cycle' => $cycle, 'price_cents' => (int) round($price * $factor), 'setup_cents' => 0]);
            }
            $created[] = $id;
        }

        if (!self::productExists('custom-server')) {
            $id = $db->insert('products', [
                'category_id' => $categoryId, 'name' => 'Custom server', 'slug' => 'custom-server',
                'description' => 'Pick your own CPU, RAM and SSD: pay only for what you choose.',
                'tier' => 'standard', 'sort' => $sort + 10, 'visible' => 1,
                'min_ram_mb' => 1024, 'max_ram_mb' => 32768, 'ram_step_mb' => 1024, 'allowed_versions_json' => $versions,
                'meta_json' => Json::encode(['software' => 'paper', 'plan_type' => 'custom']),
                'created_at' => Database::now(),
            ]);
            foreach (['monthly', 'bimonthly', 'semestral', 'annual'] as $cycle) {
                $db->insert('product_prices', ['product_id' => $id, 'cycle' => $cycle, 'price_cents' => 0, 'setup_cents' => 0]);
            }
            // Price = sum of what is chosen: 2,00 €/GB RAM, 1,50 €/vCPU, 0,50 € per 5 GB SSD.
            foreach ([['ram', 1024, 32768, 1024, 200, 'GB'], ['cpu', 100, 800, 100, 150, 'vCPU'], ['disk', 5120, 204800, 5120, 50, 'GB']] as [$resource, $min, $max, $step, $unit, $label]) {
                $db->insert('resource_options', [
                    'product_id' => $id, 'resource' => $resource, 'min_value' => $min, 'max_value' => $max,
                    'step' => $step, 'included' => 0, 'unit_cents' => $unit, 'unit' => $label, 'sort' => 0,
                ]);
            }
            $created[] = $id;
        }

        $packSlugs = array_column($packs, 0);
        $fixedIds = [];
        foreach ($fixed as $row) {
            $found = self::findProduct($row[0]);
            if ($found !== null) {
                $fixedIds[] = (int) $found['id'];
            }
        }
        foreach ($created as $productId) {
            $isFixed = in_array($productId, $fixedIds, true);
            foreach ($db->select('SELECT * FROM addons WHERE visible = 1') as $addon) {
                if (!$isFixed && PriceCalculator::addonEffect($addon) !== null) {
                    continue; // the configurator already chooses CPU, RAM and SSD
                }
                $db->insert('product_addons', ['product_id' => $productId, 'addon_id' => (int) $addon['id'], 'enabled' => 1]);
            }
        }
        // Expansion packs belong inside the fixed packages (optional) - make sure each one has them.
        foreach ($fixedIds as $productId) {
            foreach ($packSlugs as $slug) {
                $addon = $db->selectOne('SELECT id FROM addons WHERE code = :code', ['code' => $slug]);
                if ($addon !== null && $db->selectOne('SELECT 1 AS x FROM product_addons WHERE product_id = :p AND addon_id = :a', ['p' => $productId, 'a' => (int) $addon['id']]) === null) {
                    $db->insert('product_addons', ['product_id' => $productId, 'addon_id' => (int) $addon['id'], 'enabled' => 1]);
                }
            }
        }
        // New packs become available on the new products only; existing products keep their own choice.

        $hidden = 0;
        if ($hideOthers) {
            $keep = array_merge(array_column($fixed, 0), ['custom-server'], array_values(self::LEGACY_SLUGS));
            $placeholders = implode(', ', array_map(static fn (int $i): string => ':s' . $i, array_keys($keep)));
            $bind = [];
            foreach ($keep as $i => $slug) {
                $bind['s' . $i] = $slug;
            }
            $hidden = $db->execute('UPDATE products SET visible = 0 WHERE slug NOT IN (' . $placeholders . ')', $bind);
        }

        return ['products' => count($created), 'packs' => $packsAdded, 'hidden' => $hidden];
    }

    /** Slugs used by older (Italian) releases, so existing installs are not duplicated. */
    private const LEGACY_SLUGS = [
        'package-2gb' => 'pacchetto-2gb', 'package-4gb' => 'pacchetto-4gb', 'package-8gb' => 'pacchetto-8gb',
        'package-12gb' => 'pacchetto-12gb', 'package-16gb' => 'pacchetto-16gb', 'custom-server' => 'server-su-misura',
    ];

    private static function findProduct(string $slug): ?array
    {
        $db = App::db();

        return $db->selectOne('SELECT id FROM products WHERE slug = :s', ['s' => $slug])
            ?? (isset(self::LEGACY_SLUGS[$slug]) ? $db->selectOne('SELECT id FROM products WHERE slug = :s', ['s' => self::LEGACY_SLUGS[$slug]]) : null);
    }

    private static function productExists(string $slug): bool
    {
        return self::findProduct($slug) !== null;
    }

    public static function catalog(): void
    {
        $db = App::db();
        if ((int) $db->value('SELECT COUNT(*) FROM products') > 0) {
            return;
        }
        $categories = [
            ['Minecraft Java', 'minecraft-java', 'Java edition servers: Vanilla, Paper, Purpur, Fabric, Forge.'],
            ['Minecraft Bedrock', 'minecraft-bedrock', 'Bedrock edition servers with crossplay.'],
            ['Modded', 'modded', 'CurseForge, Modrinth and FTB modpacks.'],
            ['Proxy', 'proxy', 'Velocity and BungeeCord for server networks.'],
        ];
        $categoryIds = [];
        foreach ($categories as $index => [$name, $slug, $description]) {
            $categoryIds[$slug] = $db->insert('categories', [
                'name' => $name, 'slug' => $slug, 'description' => $description, 'sort' => $index, 'visible' => 1,
            ]);
        }

        foreach (['ram' => [1024, 16384, 1024, 2048, 300, 'GB'], 'cpu' => [100, 800, 100, 100, 200, 'vCPU'], 'disk' => [5120, 102400, 5120, 10240, 100, 'GB']] as $resource => [$min, $max, $step, $included, $unit, $unitLabel]) {
            $db->insert('resource_options', [
                'product_id' => null,
                'resource' => $resource,
                'min_value' => $min,
                'max_value' => $max,
                'step' => $step,
                'included' => $included,
                'unit_cents' => $unit,
                'unit' => $unitLabel,
                'sort' => 0,
            ]);
        }

        $addons = [
            ['extra_database', 'Extra MySQL database', 200, 'database'],
            ['extra_backup', 'Extra backup slot', 300, 'backup'],
            ['extra_port', 'Extra port', 150, 'port'],
            ['dedicated_ip', 'Dedicated IP', 500, 'ip'],
            ['subdomain', 'Free subdomain', 0, 'subdomain'],
            ['priority_support', 'Priority support', 700, 'support'],
            ['modpack_install', 'Assisted modpack install', 900, 'modpack'],
        ];
        foreach ($addons as [$code, $name, $price, $type]) {
            $db->insert('addons', [
                'code' => $code, 'name' => $name, 'description' => null, 'price_cents' => $price,
                'cycle' => 'monthly', 'type' => $type, 'visible' => 1,
            ]);
        }

        self::packages();

        $locationId = $db->insert('locations', [
            'name' => 'Europe 1', 'slug' => 'eu-1', 'country' => 'IT', 'pterodactyl_location_id' => 1,
            'ddos_note' => 'DDoS protection', 'visible' => 1, 'maintenance' => 0, 'sort' => 0,
        ]);
        $db->insert('locations', [
            'name' => 'Europe 2', 'slug' => 'eu-2', 'country' => 'DE', 'pterodactyl_location_id' => 2,
            'ddos_note' => 'DDoS protection', 'visible' => 1, 'maintenance' => 0, 'sort' => 1,
        ]);
        $db->insert('nodes', [
            'name' => 'node-01', 'location_id' => $locationId, 'pterodactyl_node_id' => 1,
            'memory_mb' => 65536, 'disk_mb' => 1000000, 'cpu_percent' => 1600, 'overallocation_pct' => 120,
            'monthly_cost_cents' => 12000, 'maintenance' => 0, 'visible' => 1,
        ]);

        $couponId = $db->insert('coupons', [
            'code' => 'WELCOME10', 'type' => 'percent', 'value' => 10, 'max_uses' => 100, 'uses' => 0,
            'per_user_limit' => 1, 'first_purchase_only' => 1, 'recurring' => 0, 'active' => 1,
        ]);
        unset($couponId);

        $db->insert('announcements', [
            'title' => 'Welcome',
            'body' => 'Your hosting panel is ready. Edit or remove this announcement from the admin area.',
            'level' => 'info',
            'published_at' => Database::now(),
            'visible' => 1,
        ]);
    }
}

/** Tiny wrapper so seeding does not depend on an authenticated session. */
final class AuditSeed
{
    public static function log(string $action, int $userId): void
    {
        try {
            App::db()->insert('audit_logs', [
                'actor_id' => $userId,
                'actor_type' => 'system',
                'action' => $action,
                'subject_type' => 'user',
                'subject_id' => $userId,
                'ip' => '127.0.0.1',
                'meta_json' => '{}',
                'created_at' => Database::now(),
            ]);
        } catch (\Throwable) {
        }
    }
}
