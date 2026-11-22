<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Core\Money;
use App\Core\Queue;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Domain\Attachments;
use App\Domain\Catalog;
use App\Domain\InvoiceService;
use App\Domain\LicenseClient;
use App\Domain\OrderService;
use App\Domain\ProvisioningService;
use App\Domain\Services;
use App\Domain\TicketService;
use App\Domain\UserRepository;
use App\Domain\WalletService;

final class AdminController
{
    public function dashboard(Request $request, array $params): Response
    {
        $db = App::db();
        $monthStart = gmdate('Y-m-01 00:00:00');
        $revenue = (int) $db->value('SELECT COALESCE(SUM(total_cents),0) FROM invoices WHERE status = :paid AND paid_at >= :start', ['paid' => 'paid', 'start' => $monthStart]);
        $mrr = 0;
        foreach ($db->select('SELECT price_cents, cycle FROM services WHERE status = :active', ['active' => 'active']) as $row) {
            $mrr += Money::divRound((int) $row['price_cents'], \App\Domain\PriceCalculator::months((string) $row['cycle']));
        }
        $nodes = $db->select(
            'SELECT n.*, l.name AS location_name,
                (SELECT COALESCE(SUM(s.ram_mb),0) FROM services s WHERE s.node_id = n.id AND s.status IN (:active, :installing, :pending)) AS allocated_mb
             FROM nodes n JOIN locations l ON l.id = n.location_id ORDER BY n.id ASC',
            ['active' => 'active', 'installing' => 'installing', 'pending' => 'pending']
        );
        foreach ($nodes as &$node) {
            $capacity = intdiv((int) $node['memory_mb'] * max(100, (int) $node['overallocation_pct']), 100);
            $node['capacity_mb'] = $capacity;
            $node['fill_pct'] = $capacity > 0 ? min(100, (int) round((int) $node['allocated_mb'] / $capacity * 100)) : 0;
        }

        return Response::view('admin/dashboard', [
            'revenue' => $revenue,
            'mrr' => $mrr,
            'newCustomers' => (int) $db->value('SELECT COUNT(*) FROM users WHERE created_at >= :start', ['start' => $monthStart]),
            'activeServices' => (int) $db->value('SELECT COUNT(*) FROM services WHERE status = :s', ['s' => 'active']),
            'suspendedServices' => (int) $db->value('SELECT COUNT(*) FROM services WHERE status = :s', ['s' => 'suspended']),
            'openTickets' => (int) $db->value('SELECT COUNT(*) FROM tickets WHERE status <> :closed', ['closed' => 'closed']),
            'pendingOrders' => (int) $db->value('SELECT COUNT(*) FROM orders WHERE status IN (:pending, :review)', ['pending' => 'pending_payment', 'review' => 'review']),
            'failedProvisions' => (int) $db->value('SELECT COUNT(*) FROM services WHERE provisioning_state = :state', ['state' => 'failed']),
            'nodes' => $nodes,
            'pendingTransfers' => (int) $db->value('SELECT COUNT(*) FROM wallet_topups WHERE status = :s', ['s' => 'pending']),
            'recentOrders' => $db->select('SELECT o.*, u.username FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 8'),
            'license' => LicenseClient::state(),
            'cartCount' => 0,
        ]);
    }

    public function products(Request $request, array $params): Response
    {
        $this->require('catalog');

        return Response::view('admin/products', [
            'products' => Catalog::products(null, true),
            'categories' => App::db()->select('SELECT * FROM categories ORDER BY sort ASC, id ASC'),
            'catalogSettings' => Catalog::displaySettings(),
            'cartCount' => 0,
        ]);
    }

    public function product(Request $request, array $params): Response
    {
        $this->require('catalog');
        $product = Catalog::productById((int) ($params['id'] ?? 0));
        if ($product === null) {
            throw new \App\Core\HttpException(404);
        }

        $db = App::db();
        $globals = [];
        foreach ($db->select('SELECT * FROM resource_options WHERE product_id IS NULL') as $row) {
            $globals[(string) $row['resource']] = $row;
        }
        $own = [];
        foreach ($db->select('SELECT resource FROM resource_options WHERE product_id = :id', ['id' => (int) $product['id']]) as $row) {
            $own[(string) $row['resource']] = true;
        }
        $linked = [];
        foreach ($db->select('SELECT addon_id FROM product_addons WHERE product_id = :id AND enabled = 1', ['id' => (int) $product['id']]) as $row) {
            $linked[(int) $row['addon_id']] = true;
        }

        return Response::view('admin/product', [
            'product' => $product,
            'meta' => Json::decode($product['meta_json'] ?? null),
            'globalOptions' => $globals,
            'ownOptions' => $own,
            'allAddons' => $db->select('SELECT * FROM addons ORDER BY id ASC'),
            'linkedAddons' => $linked,
            'categories' => $db->select('SELECT * FROM categories ORDER BY sort ASC'),
            'cartCount' => 0,
        ]);
    }

    /** Redirects back with an error when the licence does not allow customisation. */
    private function lockedWithoutLicense(string $back): ?Response
    {
        if (LicenseClient::allowsCustomization()) {
            return null;
        }
        flash('error', __('license_customization_blocked'));

        return Response::redirect($back);
    }

    public function saveProduct(Request $request, array $params): Response
    {
        $this->require('catalog');
        $id = (int) ($params['id'] ?? 0);
        $product = Catalog::productById($id);
        if ($product === null) {
            throw new \App\Core\HttpException(404);
        }
        $back = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/products/' . $id);
        if (($locked = $this->lockedWithoutLicense($back)) !== null) {
            return $locked;
        }

        // Prices are typed in euros ("19,99"); validate everything before writing.
        $prices = [];
        $errors = [];
        foreach (Money::cycles() as $cycle) {
            $raw = trim($request->string('price_' . $cycle));
            if ($raw === '' && $cycle !== 'monthly') {
                $prices[$cycle] = 0;
                continue;
            }
            $cents = Money::parseInput($raw);
            if ($cents === null) {
                $errors[] = __('invalid_price_for', ['field' => __($cycle), 'value' => $raw]);
                continue;
            }
            $prices[$cycle] = $cents;
        }
        // A fixed plan needs its price; a configurator may cost only what is chosen (base 0).
        if (($prices['monthly'] ?? 0) <= 0 && !isset($errors[0]) && $request->string('plan_type') === 'fixed') {
            $errors[] = __('monthly_price_required');
        }

        $resources = [];
        foreach (self::RESOURCE_FIELDS as $resource => $scale) {
            $unit = Money::parseInput($request->string('res_' . $resource . '_unit'));
            if ($unit === null) {
                $errors[] = __('invalid_price_for', ['field' => __($resource), 'value' => $request->string('res_' . $resource . '_unit')]);
                continue;
            }
            $min = max(0, $request->int('res_' . $resource . '_min')) * $scale;
            $max = max(0, $request->int('res_' . $resource . '_max')) * $scale;
            $step = max(1, $request->int('res_' . $resource . '_step')) * $scale;
            $included = max(0, $request->int('res_' . $resource . '_included')) * $scale;
            if ($min <= 0 || $max < $min) {
                $errors[] = __('invalid_resource_range', ['resource' => __($resource)]);
                continue;
            }
            $resources[$resource] = ['min_value' => $min, 'max_value' => $max, 'step' => $step, 'included' => $included, 'unit_cents' => $unit];
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                flash('error', $error);
            }

            return Response::redirect($back);
        }

        $db = App::db();
        $meta = Json::decode($product['meta_json'] ?? null);
        $meta['featured'] = $request->bool('featured');
        $meta['badge'] = mb_substr($request->string('badge'), 0, 40);
        $planType = in_array($request->string('plan_type'), Catalog::PLAN_TYPES, true) ? $request->string('plan_type') : 'custom';
        $meta['plan_type'] = $planType;
        $fixed = [
            'ram_mb' => max(0, $request->int('fixed_ram_mb')),
            'cpu_percent' => (int) round(max(0.0, (float) str_replace(',', '.', $request->string('fixed_cpu_cores'))) * 100),
            'disk_mb' => max(0, $request->int('fixed_disk_gb')) * 1024,
        ];
        if ($planType === 'fixed' && ($fixed['ram_mb'] < 128 || $fixed['cpu_percent'] < 10 || $fixed['disk_mb'] < 1024)) {
            flash('error', __('fixed_plan_invalid'));

            return Response::redirect($back);
        }
        $meta['fixed'] = $fixed;
        $ram = $resources['ram'];
        if ($planType === 'fixed') {
            $ram = ['min_value' => $fixed['ram_mb'], 'max_value' => $fixed['ram_mb']];
        }
        $categoryId = $request->int('category_id');
        if ($db->selectOne('SELECT id FROM categories WHERE id = :id', ['id' => $categoryId]) === null) {
            $categoryId = (int) $product['category_id'];
        }
        $db->update('products', [
            'name' => $request->string('name', (string) $product['name']),
            'slug' => \App\Core\Str::slug($request->string('slug', (string) $product['slug'])),
            'description' => $request->string('description'),
            'tier' => in_array($request->string('tier'), ['budget', 'standard', 'premium'], true) ? $request->string('tier') : 'standard',
            'category_id' => $categoryId,
            'sort' => $request->int('sort', (int) ($product['sort'] ?? 0)),
            'visible' => $request->bool('visible') ? 1 : 0,
            'min_ram_mb' => $ram['min_value'],
            'max_ram_mb' => $ram['max_value'],
            'nest_id' => $request->int('nest_id') ?: null,
            'egg_id' => $request->int('egg_id') ?: null,
            'docker_image' => $request->string('docker_image'),
            'startup' => $request->string('startup'),
            'meta_json' => Json::encode($meta),
            'updated_at' => Database::now(),
        ], 'id = :id', ['id' => $id]);

        foreach ($prices as $cycle => $price) {
            $existing = $db->selectOne('SELECT id FROM product_prices WHERE product_id = :p AND cycle = :c', ['p' => $id, 'c' => $cycle]);
            if ($existing === null) {
                $db->insert('product_prices', ['product_id' => $id, 'cycle' => $cycle, 'price_cents' => $price, 'setup_cents' => 0]);
            } else {
                $db->update('product_prices', ['price_cents' => $price], 'id = :id', ['id' => (int) $existing['id']]);
            }
        }

        // Resource pricing: keep a product-specific row only when it differs
        // from the global default, so later global changes still apply.
        foreach ($resources as $resource => $values) {
            $global = $db->selectOne('SELECT * FROM resource_options WHERE product_id IS NULL AND resource = :r', ['r' => $resource]);
            $own = $db->selectOne('SELECT * FROM resource_options WHERE product_id = :p AND resource = :r', ['p' => $id, 'r' => $resource]);
            $sameAsGlobal = $global !== null;
            foreach ($values as $column => $value) {
                if ($global === null || (int) $global[$column] !== $value) {
                    $sameAsGlobal = false;
                }
            }
            if ($sameAsGlobal) {
                if ($own !== null) {
                    $db->execute('DELETE FROM resource_options WHERE id = :id', ['id' => (int) $own['id']]);
                }
                continue;
            }
            if ($own === null) {
                $db->insert('resource_options', $values + [
                    'product_id' => $id,
                    'resource' => $resource,
                    'unit' => (string) ($global['unit'] ?? ($resource === 'cpu' ? '%' : 'GB')),
                    'sort' => 0,
                ]);
            } else {
                $db->update('resource_options', $values, 'id = :id', ['id' => (int) $own['id']]);
            }
        }

        $enabledAddons = array_map('intval', $request->array('addons'));
        foreach ($db->select('SELECT id FROM addons') as $addon) {
            $addonId = (int) $addon['id'];
            $enabled = in_array($addonId, $enabledAddons, true) ? 1 : 0;
            $link = $db->selectOne('SELECT id FROM product_addons WHERE product_id = :p AND addon_id = :a', ['p' => $id, 'a' => $addonId]);
            if ($link === null) {
                if ($enabled === 1) {
                    $db->insert('product_addons', ['product_id' => $id, 'addon_id' => $addonId, 'enabled' => 1]);
                }
            } else {
                $db->update('product_addons', ['enabled' => $enabled], 'id = :id', ['id' => (int) $link['id']]);
            }
        }

        Audit::log('admin.product_updated', 'product', $id, ['prices' => $prices]);
        flash('success', __('saved'));

        return Response::redirect($back);
    }

    public function createProduct(Request $request, array $params): Response
    {
        $this->require('catalog');
        $base = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/products');
        if (($locked = $this->lockedWithoutLicense($base)) !== null) {
            return $locked;
        }
        $db = App::db();
        $name = trim($request->string('name'));
        $categoryId = $request->int('category_id');
        if ($name === '' || $db->selectOne('SELECT id FROM categories WHERE id = :id', ['id' => $categoryId]) === null) {
            flash('error', __('product_create_invalid'));

            return Response::redirect($base);
        }
        $planType = $request->string('plan_type') === 'fixed' ? 'fixed' : 'custom';
        $id = $db->insert('products', [
            'category_id' => $categoryId,
            'name' => mb_substr($name, 0, 160),
            'slug' => $this->uniqueProductSlug($name),
            'description' => '',
            'tier' => 'standard',
            'sort' => (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM products'),
            'visible' => 0,
            'min_ram_mb' => 2048,
            'max_ram_mb' => $planType === 'fixed' ? 2048 : 16384,
            'ram_step_mb' => 1024,
            'meta_json' => Json::encode([
                'plan_type' => $planType,
                'software' => 'paper',
                'fixed' => ['ram_mb' => 2048, 'cpu_percent' => 100, 'disk_mb' => 10240],
            ]),
            'created_at' => Database::now(),
            'updated_at' => Database::now(),
        ]);
        foreach (Money::cycles() as $cycle) {
            $db->insert('product_prices', ['product_id' => $id, 'cycle' => $cycle, 'price_cents' => $cycle === 'monthly' ? 500 : 0, 'setup_cents' => 0]);
        }
        Audit::log('admin.product_created', 'product', $id, ['plan_type' => $planType]);
        flash('success', __('product_created'));

        return Response::redirect($base . '/' . $id);
    }

    public function duplicateProduct(Request $request, array $params): Response
    {
        $this->require('catalog');
        $base = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/products');
        if (($locked = $this->lockedWithoutLicense($base)) !== null) {
            return $locked;
        }
        $db = App::db();
        $source = $db->selectOne('SELECT * FROM products WHERE id = :id', ['id' => (int) ($params['id'] ?? 0)]);
        if ($source === null) {
            throw new \App\Core\HttpException(404);
        }
        $copy = $source;
        unset($copy['id']);
        $copy['name'] = mb_substr((string) $source['name'] . ' ' . __('copy_suffix'), 0, 160);
        $copy['slug'] = $this->uniqueProductSlug((string) $source['slug'] . '-copy');
        $copy['visible'] = 0;
        $copy['created_at'] = Database::now();
        $copy['updated_at'] = Database::now();
        $id = $db->insert('products', $copy);
        foreach ($db->select('SELECT * FROM product_prices WHERE product_id = :id', ['id' => (int) $source['id']]) as $row) {
            unset($row['id']);
            $db->insert('product_prices', ['product_id' => $id] + $row);
        }
        foreach ($db->select('SELECT * FROM resource_options WHERE product_id = :id', ['id' => (int) $source['id']]) as $row) {
            unset($row['id']);
            $db->insert('resource_options', ['product_id' => $id] + $row);
        }
        foreach ($db->select('SELECT * FROM product_addons WHERE product_id = :id', ['id' => (int) $source['id']]) as $row) {
            unset($row['id']);
            $db->insert('product_addons', ['product_id' => $id] + $row);
        }
        Audit::log('admin.product_duplicated', 'product', $id, ['from' => (int) $source['id']]);
        flash('success', __('product_duplicated'));

        return Response::redirect($base . '/' . $id);
    }

    public function deleteProduct(Request $request, array $params): Response
    {
        $this->require('catalog');
        $base = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/products');
        if (($locked = $this->lockedWithoutLicense($base)) !== null) {
            return $locked;
        }
        $db = App::db();
        $id = (int) ($params['id'] ?? 0);
        $product = $db->selectOne('SELECT * FROM products WHERE id = :id', ['id' => $id]);
        if ($product === null) {
            throw new \App\Core\HttpException(404);
        }
        $used = (int) $db->value('SELECT COUNT(*) FROM order_items WHERE product_id = :id', ['id' => $id])
            + (int) $db->value('SELECT COUNT(*) FROM services WHERE product_id = :id', ['id' => $id]);
        if ($used > 0) {
            flash('error', __('product_in_use'));

            return Response::redirect($base . '/' . $id);
        }
        $db->transaction(static function (\App\Core\Database $db) use ($id): void {
            foreach (['cart_items', 'product_prices', 'resource_options', 'product_addons'] as $table) {
                $db->execute('DELETE FROM ' . $table . ' WHERE product_id = :id', ['id' => $id]);
            }
            $db->execute('DELETE FROM products WHERE id = :id', ['id' => $id]);
        });
        Audit::log('admin.product_deleted', 'product', $id, ['name' => (string) $product['name']]);
        flash('success', __('product_deleted', ['name' => (string) $product['name']]));

        return Response::redirect($base);
    }

    public function saveCatalogSettings(Request $request, array $params): Response
    {
        $this->require('catalog');
        $base = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/products');
        if (($locked = $this->lockedWithoutLicense($base)) !== null) {
            return $locked;
        }
        App::setSetting('catalog_show_fixed', $request->bool('catalog_show_fixed'));
        App::setSetting('catalog_show_custom', $request->bool('catalog_show_custom'));
        App::setSetting('catalog_show_packs', $request->bool('catalog_show_packs'));
        App::setSetting('catalog_order', $request->string('catalog_order') === 'custom_first' ? 'custom_first' : 'fixed_first');
        foreach (['catalog_fixed_title', 'catalog_fixed_lead', 'catalog_custom_title', 'catalog_custom_lead', 'catalog_packs_title', 'catalog_packs_lead'] as $key) {
            App::setSetting($key, mb_substr($request->string($key), 0, 300));
        }
        Audit::log('admin.catalog_settings', 'settings', null, []);
        flash('success', __('saved'));

        return Response::redirect($base);
    }

    private function uniqueProductSlug(string $text): string
    {
        $slug = \App\Core\Str::slug($text);
        $slug = $slug !== '' ? $slug : 'server';
        $candidate = $slug;
        $i = 2;
        while (App::db()->selectOne('SELECT id FROM products WHERE slug = :s', ['s' => $candidate]) !== null) {
            $candidate = $slug . '-' . $i++;
        }

        return $candidate;
    }

    /** Resource => multiplier between the admin form unit and the stored value (RAM in MB, CPU in vCPU → %, disk GB → MB). */
    private const RESOURCE_FIELDS = ['ram' => 1, 'cpu' => 100, 'disk' => 1024];

    public function addons(Request $request, array $params): Response
    {
        $this->require('catalog');

        return Response::view('admin/addons', [
            'addons' => App::db()->select(
                'SELECT a.*, (SELECT COUNT(*) FROM product_addons pa WHERE pa.addon_id = a.id AND pa.enabled = 1) AS products
                 FROM addons a ORDER BY a.id ASC'
            ),
            'cartCount' => 0,
        ]);
    }

    public function saveAddon(Request $request, array $params): Response
    {
        $this->require('catalog');
        $base = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/addons');
        if (($locked = $this->lockedWithoutLicense($base)) !== null) {
            return $locked;
        }
        $id = (int) ($params['id'] ?? 0);
        $name = trim($request->string('name'));
        $price = Money::parseInput($request->string('price'));
        if ($name === '' || $price === null) {
            flash('error', $price === null ? __('invalid_price_for', ['field' => $name !== '' ? $name : __('addon'), 'value' => $request->string('price')]) : __('addon_name_required'));

            return Response::redirect($base);
        }
        $db = App::db();
        $data = [
            'name' => mb_substr($name, 0, 120),
            'description' => $request->string('description') !== '' ? $request->string('description') : null,
            'price_cents' => $price,
            'visible' => $request->bool('visible') ? 1 : 0,
        ];
        // Expansion pack effect: +RAM (GB), +vCPU (cores) or +SSD (GB).
        $effect = $request->string('effect_resource');
        $amountInput = (float) str_replace(',', '.', $request->string('effect_amount'));
        $multipliers = ['ram' => 1024, 'cpu' => 100, 'disk' => 1024];
        $existingMeta = $id > 0 ? Json::decode($db->value('SELECT meta_json FROM addons WHERE id = :id', ['id' => $id])) : [];
        unset($existingMeta['resource'], $existingMeta['amount']);
        if (isset($multipliers[$effect]) && $amountInput > 0) {
            $existingMeta['resource'] = $effect;
            $existingMeta['amount'] = (int) round($amountInput * $multipliers[$effect]);
        }
        $data['meta_json'] = Json::encode($existingMeta);
        if ($id > 0) {
            if ($db->selectOne('SELECT id FROM addons WHERE id = :id', ['id' => $id]) === null) {
                throw new \App\Core\HttpException(404);
            }
            $db->update('addons', $data, 'id = :id', ['id' => $id]);
            Audit::log('admin.addon_updated', 'addon', $id, ['price_cents' => $price]);
        } else {
            $code = Str::slug($request->string('code') !== '' ? $request->string('code') : $name);
            $code = str_replace('-', '_', $code);
            if ($code === '' || $db->selectOne('SELECT id FROM addons WHERE code = :c', ['c' => $code]) !== null) {
                $code .= '_' . bin2hex(random_bytes(2));
            }
            $type = preg_replace('/[^a-z_]/', '', strtolower($request->string('type', 'extra'))) ?: 'extra';
            $id = $db->insert('addons', $data + ['code' => $code, 'cycle' => 'monthly', 'type' => $type]);
            if ($request->bool('attach_all')) {
                foreach ($db->select('SELECT id FROM products') as $product) {
                    $db->insert('product_addons', ['product_id' => (int) $product['id'], 'addon_id' => $id, 'enabled' => 1]);
                }
            }
            Audit::log('admin.addon_created', 'addon', $id, ['price_cents' => $price]);
        }
        flash('success', __('saved'));

        return Response::redirect($base);
    }

    public function services(Request $request, array $params): Response
    {
        $this->require('services');
        $status = $request->string('status');
        $sql = 'SELECT s.*, u.username, p.name AS product_name FROM services s
                JOIN users u ON u.id = s.user_id JOIN products p ON p.id = s.product_id';
        $bind = [];
        if ($status !== '') {
            $sql .= ' WHERE s.status = :status';
            $bind['status'] = $status;
        }
        $sql .= ' ORDER BY s.id DESC LIMIT 100';

        return Response::view('admin/services', [
            'services' => App::db()->select($sql, $bind),
            'status' => $status,
            'cartCount' => 0,
        ]);
    }

    public function serviceAction(Request $request, array $params): Response
    {
        $this->require('services');
        $service = Services::find((int) ($params['id'] ?? 0));
        if ($service === null) {
            throw new \App\Core\HttpException(404);
        }
        $action = $request->string('action');
        try {
            switch ($action) {
                case 'suspend':
                    ProvisioningService::suspend($service);
                    break;
                case 'unsuspend':
                    ProvisioningService::unsuspend($service);
                    break;
                case 'terminate':
                    ProvisioningService::terminate($service);
                    break;
                case 'provision':
                    Queue::push(\App\Domain\Jobs\ProvisionServerJob::class, ['service_id' => (int) $service['id']], 0, 'provisioning');
                    flash('success', __('provisioning_queued'));
                    break;
                case 'apply':
                    ProvisioningService::applyResources($service);
                    flash('success', __('saved'));
                    break;
                case 'extend':
                    Services::extend($service);
                    flash('success', __('saved'));
                    break;
                default:
                    throw new \App\Core\HttpException(422, 'Unknown action');
            }
            Audit::log('admin.service_action', 'service', (int) $service['id'], ['action' => $action]);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/services'));
    }

    public function orders(Request $request, array $params): Response
    {
        $this->require('orders');
        $orders = App::db()->select(
            'SELECT o.*, u.username, i.number AS invoice_number FROM orders o
             JOIN users u ON u.id = o.user_id LEFT JOIN invoices i ON i.id = o.invoice_id
             ORDER BY o.id DESC LIMIT 100'
        );

        return Response::view('admin/orders', ['orders' => $orders, 'cartCount' => 0]);
    }

    public function invoices(Request $request, array $params): Response
    {
        $this->require('invoices');
        [$where, $bind] = $this->invoiceFilters($request);
        $invoices = App::db()->select(
            'SELECT i.*, u.username, u.email_enc FROM invoices i JOIN users u ON u.id = i.user_id
             WHERE ' . $where . ' ORDER BY i.id DESC LIMIT 200',
            $bind
        );
        foreach ($invoices as &$invoice) {
            $invoice['email'] = UserRepository::present($invoice)['email'];
        }
        unset($invoice);
        $summary = App::db()->selectOne(
            'SELECT COUNT(*) AS count, COALESCE(SUM(i.total_cents),0) AS total,
                COALESCE(SUM(CASE WHEN i.status = :paid THEN i.total_cents ELSE 0 END),0) AS paid_total
             FROM invoices i JOIN users u ON u.id = i.user_id WHERE ' . $where,
            $bind + ['paid' => 'paid']
        );

        return Response::view('admin/invoices', [
            'invoices' => $invoices,
            'status' => $request->string('status'),
            'term' => trim($request->string('q')),
            'summary' => $summary,
        ]);
    }

    /** @return array{0:string,1:array<string,string>} */
    private function invoiceFilters(Request $request): array
    {
        $where = ['1 = 1'];
        $bind = [];
        $status = $request->string('status');
        if (in_array($status, ['pending', 'paid', 'void', 'overdue'], true)) {
            $where[] = 'i.status = :status';
            $bind['status'] = $status;
        }
        $term = trim($request->string('q'));
        if ($term !== '') {
            $where[] = '(i.number LIKE :term OR u.username LIKE :term OR i.customer_json LIKE :term)';
            $bind['term'] = '%' . $term . '%';
        }

        return [implode(' AND ', $where), $bind];
    }

    public function exportInvoices(Request $request, array $params): Response
    {
        $this->require('invoices');
        [$where, $bind] = $this->invoiceFilters($request);
        $rows = App::db()->select(
            'SELECT i.*, u.username, u.email_enc FROM invoices i JOIN users u ON u.id = i.user_id
             WHERE ' . $where . ' ORDER BY i.id ASC',
            $bind
        );
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['number', 'year', 'client', 'email', 'status', 'subtotal', 'discount', 'tax', 'total', 'currency', 'issued_at', 'due_at', 'paid_at', 'tax_mode'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'csvCell'], [
                (string) $row['number'],
                (string) $row['year'],
                (string) $row['username'],
                UserRepository::present($row)['email'],
                (string) $row['status'],
                number_format((int) $row['subtotal_cents'] / 100, 2, '.', ''),
                number_format((int) $row['discount_cents'] / 100, 2, '.', ''),
                number_format((int) $row['tax_cents'] / 100, 2, '.', ''),
                number_format((int) $row['total_cents'] / 100, 2, '.', ''),
                (string) $row['currency'],
                (string) $row['created_at'],
                (string) ($row['due_at'] ?? ''),
                (string) ($row['paid_at'] ?? ''),
                (string) $row['tax_mode'],
            ]), ',', '"', '');
        }
        rewind($handle);
        $body = "\xEF\xBB\xBF" . (string) stream_get_contents($handle);
        fclose($handle);
        Audit::log('invoices.exported', 'invoice', null, ['rows' => count($rows)]);

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="invoices-' . gmdate('Ymd') . '.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function csvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }

    public function invoicePdf(Request $request, array $params): Response
    {
        $this->require('invoices');
        $invoice = App::db()->selectOne('SELECT * FROM invoices WHERE id = :id', ['id' => (int) ($params['id'] ?? 0)]);
        if ($invoice === null) {
            throw new \App\Core\HttpException(404);
        }
        $path = InvoiceService::pdf((int) $invoice['id']);
        $body = (string) file_get_contents($path);

        return new Response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $invoice['number'] . '.pdf"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function confirmBankTransfer(Request $request, array $params): Response
    {
        $this->require('orders');
        $order = App::db()->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => (int) ($params['id'] ?? 0)]);
        if ($order === null) {
            throw new \App\Core\HttpException(404);
        }
        try {
            OrderService::markPaid((int) $order['id'], 'bank', (int) $order['total_cents'], 'bank:' . $order['number']);
            flash('success', __('payment_confirmed'));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/orders'));
    }

    public function clients(Request $request, array $params): Response
    {
        $this->require('clients');
        $term = $request->string('q');
        $users = UserRepository::search($term, 200);
        $statusFilter = $request->string('status');
        if (in_array($statusFilter, UserRepository::STATUSES, true)) {
            $users = array_values(array_filter($users, static fn (array $u): bool => ($u['status'] ?? '') === $statusFilter));
        }
        foreach ($users as &$user) {
            $user['profile'] = UserRepository::present($user);
            $user['services_count'] = (int) App::db()->value('SELECT COUNT(*) FROM services WHERE user_id = :id', ['id' => (int) $user['id']]);
            $user['wallet'] = WalletService::balance((int) $user['id']);
        }

        return Response::view('admin/clients', ['users' => $users, 'term' => $term, 'statusFilter' => $statusFilter, 'cartCount' => 0]);
    }

    public function client(Request $request, array $params): Response
    {
        $this->require('clients');
        $user = $this->managedUser((int) ($params['id'] ?? 0));
        $id = (int) $user['id'];
        $db = App::db();

        return Response::view('admin/client', [
            'client' => $user,
            'profile' => UserRepository::present($user),
            'services' => $db->select('SELECT id, name, status, cycle, price_cents, next_due_at FROM services WHERE user_id = :id ORDER BY id DESC', ['id' => $id]),
            'invoices' => $db->select('SELECT id, number, status, total_cents, created_at FROM invoices WHERE user_id = :id ORDER BY id DESC LIMIT 10', ['id' => $id]),
            'sessions' => (int) $db->value('SELECT COUNT(*) FROM user_sessions WHERE user_id = :id AND revoked_at IS NULL', ['id' => $id]),
            'wallet' => WalletService::balance($id),
            'canManage' => $this->canManageUser($user),
            'isSelf' => $id === (int) Auth::id(),
            'cartCount' => 0,
        ]);
    }

    public function updateClient(Request $request, array $params): Response
    {
        $this->require('clients');
        $user = $this->managedUser((int) ($params['id'] ?? 0));
        $back = $this->clientUrl((int) $user['id']);
        if (!$this->canManageUser($user)) {
            throw new \App\Core\HttpException(403, __('forbidden'));
        }
        $id = (int) $user['id'];
        $username = trim($request->string('username'));
        if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,32}$/', $username)) {
            flash('error', __('username_invalid'));

            return Response::redirect($back);
        }
        $taken = App::db()->selectOne('SELECT id FROM users WHERE username = :u AND id <> :id', ['u' => $username, 'id' => $id]);
        if ($taken !== null) {
            flash('error', __('username_taken'));

            return Response::redirect($back);
        }
        try {
            if (mb_strtolower(trim($request->string('email'))) !== UserRepository::present($user)['email']) {
                UserRepository::changeEmail($id, $request->string('email'));
            }
        } catch (\App\Core\HttpException $e) {
            flash('error', $e->getMessage());

            return Response::redirect($back);
        }
        UserRepository::updateProfile($id, [
            'username' => $username,
            'company' => $request->string('company'),
            'phone' => $request->string('phone'),
            'address' => $request->string('address'),
            'city' => $request->string('city'),
            'postal_code' => $request->string('postal_code'),
            'country' => strtoupper(substr($request->string('country', 'IT'), 0, 2)),
        ]);
        App::db()->update('users', ['notes' => $request->string('notes') !== '' ? mb_substr($request->string('notes'), 0, 5000) : null], 'id = :id', ['id' => $id]);
        Audit::log('admin.client_updated', 'user', $id, []);
        flash('success', __('saved'));

        return Response::redirect($back);
    }

    public function clientStatus(Request $request, array $params): Response
    {
        $this->require('clients');
        $user = $this->managedUser((int) ($params['id'] ?? 0));
        $id = (int) $user['id'];
        $back = $this->clientUrl($id);
        $status = $request->string('status');
        if (!in_array($status, UserRepository::STATUSES, true)) {
            throw new \App\Core\HttpException(422);
        }
        if (!$this->canManageUser($user)) {
            throw new \App\Core\HttpException(403, __('forbidden'));
        }
        if ($status !== 'active' && $id === (int) Auth::id()) {
            flash('error', __('cannot_block_yourself'));

            return Response::redirect($back);
        }
        if ($status !== 'active' && $this->isLastAdmin($user)) {
            flash('error', __('cannot_remove_last_admin'));

            return Response::redirect($back);
        }
        UserRepository::setStatus($id, $status);
        Audit::log('admin.client_status', 'user', $id, ['status' => $status, 'reason' => mb_substr($request->string('reason'), 0, 255)]);
        flash('success', __('client_status_changed_' . $status));

        return Response::redirect($back);
    }

    public function clientPassword(Request $request, array $params): Response
    {
        $this->require('clients');
        $user = $this->managedUser((int) ($params['id'] ?? 0));
        $id = (int) $user['id'];
        $back = $this->clientUrl($id);
        if (!$this->canManageUser($user)) {
            throw new \App\Core\HttpException(403, __('forbidden'));
        }
        $password = $request->string('password');
        $generated = $password === '';
        if ($generated) {
            $password = substr(strtr(base64_encode(random_bytes(18)), '+/', 'Kx'), 0, 16);
        }
        if (strlen($password) < 10) {
            flash('error', __('password_too_short'));

            return Response::redirect($back);
        }
        UserRepository::setPassword($id, $password);
        $update = ['failed_logins' => 0, 'locked_until' => null];
        if ($request->bool('disable_2fa')) {
            $update['totp_enabled'] = 0;
            $update['totp_secret_enc'] = null;
            $update['recovery_codes_enc'] = null;
        }
        App::db()->update('users', $update, 'id = :id', ['id' => $id]);
        if ($id !== (int) Auth::id()) {
            UserRepository::revokeSessions($id);
        }
        Audit::log('admin.client_password_reset', 'user', $id, ['disable_2fa' => $request->bool('disable_2fa')]);
        flash('success', $generated ? __('client_password_generated', ['password' => $password]) : __('client_password_set'));

        return Response::redirect($back);
    }

    public function deleteClient(Request $request, array $params): Response
    {
        $this->require('clients');
        $user = $this->managedUser((int) ($params['id'] ?? 0));
        $id = (int) $user['id'];
        $back = $this->clientUrl($id);
        if (!$this->canManageUser($user)) {
            throw new \App\Core\HttpException(403, __('forbidden'));
        }
        if ($id === (int) Auth::id()) {
            flash('error', __('cannot_delete_yourself'));

            return Response::redirect($back);
        }
        if ($this->isLastAdmin($user)) {
            flash('error', __('cannot_remove_last_admin'));

            return Response::redirect($back);
        }
        if (trim($request->string('confirm_username')) !== (string) $user['username']) {
            flash('error', __('delete_confirm_mismatch'));

            return Response::redirect($back);
        }
        $active = (int) App::db()->value(
            'SELECT COUNT(*) FROM services WHERE user_id = :user AND status IN (:active, :suspended)',
            ['user' => $id, 'active' => 'active', 'suspended' => 'suspended']
        );
        if ($active > 0) {
            flash('error', __('delete_has_services', ['count' => (string) $active]));

            return Response::redirect($back);
        }
        UserRepository::revokeSessions($id);
        UserRepository::anonymise($id);
        Audit::log('admin.client_deleted', 'user', $id, []);
        flash('success', __('client_deleted'));

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/clients'));
    }

    /** @return array<string,mixed> */
    private function managedUser(int $id): array
    {
        $user = App::db()->selectOne('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        if ($user === null || ($user['status'] ?? '') === 'deleted') {
            throw new \App\Core\HttpException(404);
        }

        return $user;
    }

    /** Staff accounts can be changed only by a super admin. */
    private function canManageUser(array $user): bool
    {
        if (($user['role'] ?? 'customer') === 'customer') {
            return true;
        }

        return (string) ((Auth::user() ?? [])['role'] ?? '') === 'super_admin';
    }

    private function isLastAdmin(array $user): bool
    {
        if (($user['role'] ?? 'customer') !== 'super_admin') {
            return false;
        }

        return (int) App::db()->value(
            "SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active' AND deleted_at IS NULL AND id <> :id",
            ['id' => (int) $user['id']]
        ) === 0;
    }

    private function clientUrl(int $id): string
    {
        return url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/clients/' . $id);
    }

    /**
     * Deletes orders (single or selected), paid ones included. Invoices and
     * payments are kept for the accounts; servers already created keep running
     * and are only detached from the deleted order.
     */
    public function deleteOrders(Request $request, array $params): Response
    {
        $this->require('orders');
        $back = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/orders');
        $ids = $request->string('single') !== ''
            ? [(int) $request->string('single')]
            : array_values(array_unique(array_filter(array_map('intval', $request->array('ids')))));
        if ($ids === []) {
            flash('error', __('orders_none_selected'));

            return Response::redirect($back);
        }
        $db = App::db();
        $deleted = 0;
        $paidDeleted = 0;
        foreach ($ids as $orderId) {
            $order = $db->selectOne('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
            if ($order === null) {
                continue;
            }
            $wasPaid = in_array((string) $order['status'], ['paid', 'provisioning', 'active', 'refunded'], true)
                || (int) $db->value('SELECT COUNT(*) FROM invoices WHERE order_id = :id AND status = :s', ['id' => $orderId, 's' => 'paid']) > 0;
            $db->transaction(static function (\App\Core\Database $db) use ($orderId): void {
                // Unpaid numbered invoices are voided; paid invoices and payments stay as they are.
                $db->execute('UPDATE invoices SET status = :void, updated_at = :now WHERE order_id = :id AND status NOT IN (:paid, :refunded)', ['void' => 'void', 'now' => Database::now(), 'id' => $orderId, 'paid' => 'paid', 'refunded' => 'refunded']);
                $db->execute('UPDATE payments SET status = :cancelled, updated_at = :now WHERE order_id = :id AND status NOT IN (:succeeded, :refunded)', ['cancelled' => 'cancelled', 'now' => Database::now(), 'id' => $orderId, 'succeeded' => 'succeeded', 'refunded' => 'refunded']);
                // Servers keep running: only the link to the order goes.
                $db->execute('UPDATE services SET order_id = NULL WHERE order_id = :id', ['id' => $orderId]);
                foreach ($db->select('SELECT coupon_id FROM coupon_redemptions WHERE order_id = :id', ['id' => $orderId]) as $redemption) {
                    $db->execute('UPDATE coupons SET uses = CASE WHEN uses > 0 THEN uses - 1 ELSE 0 END WHERE id = :id', ['id' => (int) $redemption['coupon_id']]);
                }
                $db->execute('DELETE FROM coupon_redemptions WHERE order_id = :id', ['id' => $orderId]);
                $db->execute('DELETE FROM order_items WHERE order_id = :id', ['id' => $orderId]);
                $db->execute('DELETE FROM orders WHERE id = :id', ['id' => $orderId]);
            });
            Audit::log('admin.order_deleted', 'order', $orderId, ['number' => (string) $order['number'], 'status' => (string) $order['status'], 'total' => (int) $order['total_cents'], 'user' => (int) $order['user_id']]);
            $deleted++;
            if ($wasPaid) {
                $paidDeleted++;
            }
        }
        if ($deleted > 0) {
            flash('success', __('orders_deleted', ['count' => (string) $deleted]));
        }
        if ($paidDeleted > 0) {
            flash('warning', __('orders_paid_deleted_note', ['count' => (string) $paidDeleted]));
        }

        return Response::redirect($back);
    }

    public function nodes(Request $request, array $params): Response
    {
        $this->require('pterodactyl');
        $db = App::db();
        $nodes = $db->select('SELECT n.*, l.name AS location_name FROM nodes n LEFT JOIN locations l ON l.id = n.location_id ORDER BY n.id ASC');
        foreach ($nodes as &$node) {
            $usage = $db->selectOne(
                'SELECT COUNT(*) AS services, COALESCE(SUM(ram_mb), 0) AS ram, COALESCE(SUM(disk_mb), 0) AS disk FROM services
                 WHERE node_id = :node AND status IN (:active, :installing, :pending, :suspended)',
                ['node' => (int) $node['id'], 'active' => 'active', 'installing' => 'installing', 'pending' => 'pending', 'suspended' => 'suspended']
            ) ?? [];
            $node['services'] = (int) ($usage['services'] ?? 0);
            $node['ram_used'] = (int) ($usage['ram'] ?? 0);
            $node['disk_used'] = (int) ($usage['disk'] ?? 0);
            $node['ram_capacity'] = intdiv((int) $node['memory_mb'] * max(100, (int) $node['overallocation_pct']), 100);
            $node['total_services'] = (int) $db->value('SELECT COUNT(*) FROM services WHERE node_id = :node', ['node' => (int) $node['id']]);
        }
        unset($node);
        $locations = $db->select('SELECT l.*, (SELECT COUNT(*) FROM nodes n WHERE n.location_id = l.id) AS nodes FROM locations l ORDER BY l.sort ASC, l.id ASC');

        return Response::view('admin/nodes', [
            'nodes' => $nodes,
            'locations' => $locations,
            'pterodactylReady' => ProvisioningService::configured(),
            'cartCount' => 0,
        ]);
    }

    public function saveNode(Request $request, array $params): Response
    {
        $this->require('pterodactyl');
        $back = $this->nodesUrl();
        $db = App::db();
        $id = (int) ($params['id'] ?? 0);
        if ($id > 0 && $db->selectOne('SELECT id FROM nodes WHERE id = :id', ['id' => $id]) === null) {
            throw new \App\Core\HttpException(404);
        }
        $name = trim($request->string('name'));
        $locationId = $request->int('location_id');
        $cost = Money::parseInput($request->string('monthly_cost') !== '' ? $request->string('monthly_cost') : '0');
        $errors = [];
        if ($name === '') {
            $errors[] = __('node_name_required');
        }
        if ($db->selectOne('SELECT id FROM locations WHERE id = :id', ['id' => $locationId]) === null) {
            $errors[] = __('node_location_required');
        }
        if ($request->int('pterodactyl_node_id') <= 0) {
            $errors[] = __('node_ptero_id_required');
        }
        if ($request->int('memory_mb') <= 0) {
            $errors[] = __('node_memory_required');
        }
        if ($cost === null) {
            $errors[] = __('invalid_price_for', ['field' => __('monthly_cost'), 'value' => $request->string('monthly_cost')]);
        }
        $duplicate = $db->selectOne('SELECT id FROM nodes WHERE pterodactyl_node_id = :p AND id <> :id', ['p' => $request->int('pterodactyl_node_id'), 'id' => $id]);
        if ($duplicate !== null) {
            $errors[] = __('node_ptero_id_taken');
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                flash('error', $error);
            }

            return Response::redirect($back);
        }
        $data = [
            'name' => mb_substr($name, 0, 120),
            'location_id' => $locationId,
            'pterodactyl_node_id' => $request->int('pterodactyl_node_id'),
            'memory_mb' => max(0, $request->int('memory_mb')),
            'disk_mb' => max(0, $request->int('disk_mb')),
            'cpu_percent' => max(0, $request->int('cpu_percent')),
            'overallocation_pct' => max(100, min(1000, $request->int('overallocation_pct', 100))),
            'monthly_cost_cents' => (int) $cost,
            'maintenance' => $request->bool('maintenance') ? 1 : 0,
            'visible' => $request->bool('visible') ? 1 : 0,
        ];
        if ($id > 0) {
            $db->update('nodes', $data, 'id = :id', ['id' => $id]);
        } else {
            $id = $db->insert('nodes', $data);
        }
        Audit::log('admin.node_saved', 'node', $id, $data);
        flash('success', __('node_saved', ['name' => $data['name']]));

        return Response::redirect($back . '#node-' . $id);
    }

    public function deleteNode(Request $request, array $params): Response
    {
        $this->require('pterodactyl');
        $db = App::db();
        $id = (int) ($params['id'] ?? 0);
        $node = $db->selectOne('SELECT * FROM nodes WHERE id = :id', ['id' => $id]);
        if ($node === null) {
            throw new \App\Core\HttpException(404);
        }
        $services = (int) $db->value('SELECT COUNT(*) FROM services WHERE node_id = :id', ['id' => $id]);
        if ($services > 0) {
            flash('error', __('node_has_services', ['count' => (string) $services]));

            return Response::redirect($this->nodesUrl());
        }
        $db->execute('DELETE FROM nodes WHERE id = :id', ['id' => $id]);
        Audit::log('admin.node_deleted', 'node', $id, ['name' => (string) $node['name']]);
        flash('success', __('node_deleted', ['name' => (string) $node['name']]));

        return Response::redirect($this->nodesUrl());
    }

    public function saveLocation(Request $request, array $params): Response
    {
        $this->require('pterodactyl');
        $db = App::db();
        $id = (int) ($params['id'] ?? 0);
        $name = trim($request->string('name'));
        if ($name === '') {
            flash('error', __('location_name_required'));

            return Response::redirect($this->nodesUrl());
        }
        $slug = \App\Core\Str::slug($request->string('slug') !== '' ? $request->string('slug') : $name);
        if ($db->selectOne('SELECT id FROM locations WHERE slug = :s AND id <> :id', ['s' => $slug, 'id' => $id]) !== null) {
            $slug .= '-' . bin2hex(random_bytes(2));
        }
        $data = [
            'name' => mb_substr($name, 0, 120),
            'slug' => $slug,
            'country' => strtoupper(substr(preg_replace('/[^a-z]/i', '', $request->string('country', 'IT')) ?: 'IT', 0, 2)),
            'pterodactyl_location_id' => $request->int('pterodactyl_location_id') ?: null,
            'ddos_note' => $request->string('ddos_note') !== '' ? mb_substr($request->string('ddos_note'), 0, 255) : null,
            'visible' => $request->bool('visible') ? 1 : 0,
            'maintenance' => $request->bool('maintenance') ? 1 : 0,
        ];
        if ($id > 0) {
            if ($db->selectOne('SELECT id FROM locations WHERE id = :id', ['id' => $id]) === null) {
                throw new \App\Core\HttpException(404);
            }
            $db->update('locations', $data, 'id = :id', ['id' => $id]);
        } else {
            $id = $db->insert('locations', $data + ['sort' => (int) $db->value('SELECT COALESCE(MAX(sort), 0) + 1 FROM locations')]);
        }
        Audit::log('admin.location_saved', 'location', $id, $data);
        flash('success', __('location_saved', ['name' => $data['name']]));

        return Response::redirect($this->nodesUrl() . '#locations');
    }

    public function deleteLocation(Request $request, array $params): Response
    {
        $this->require('pterodactyl');
        $db = App::db();
        $id = (int) ($params['id'] ?? 0);
        $location = $db->selectOne('SELECT * FROM locations WHERE id = :id', ['id' => $id]);
        if ($location === null) {
            throw new \App\Core\HttpException(404);
        }
        $nodes = (int) $db->value('SELECT COUNT(*) FROM nodes WHERE location_id = :id', ['id' => $id]);
        $services = (int) $db->value('SELECT COUNT(*) FROM services WHERE location_id = :id', ['id' => $id]);
        if ($nodes > 0 || $services > 0) {
            flash('error', __('location_in_use'));

            return Response::redirect($this->nodesUrl() . '#locations');
        }
        $db->execute('DELETE FROM locations WHERE id = :id', ['id' => $id]);
        Audit::log('admin.location_deleted', 'location', $id, ['name' => (string) $location['name']]);
        flash('success', __('location_deleted', ['name' => (string) $location['name']]));

        return Response::redirect($this->nodesUrl() . '#locations');
    }

    /** Imports locations and nodes from the Pterodactyl Application API (adds new ones, updates capacity). */
    public function syncNodes(Request $request, array $params): Response
    {
        $this->require('pterodactyl');
        if (!ProvisioningService::configured()) {
            flash('error', __('pterodactyl_not_configured'));

            return Response::redirect($this->nodesUrl());
        }
        $db = App::db();
        try {
            $api = ProvisioningService::application();
            $locationMap = [];
            foreach ($api->locations() as $remote) {
                $remoteId = (int) ($remote['id'] ?? 0);
                $local = $db->selectOne('SELECT id FROM locations WHERE pterodactyl_location_id = :p', ['p' => $remoteId]);
                if ($local === null) {
                    $name = trim((string) ($remote['long'] ?? '')) !== '' ? (string) $remote['long'] : (string) ($remote['short'] ?? 'Location ' . $remoteId);
                    $slug = \App\Core\Str::slug((string) ($remote['short'] ?? $name));
                    if ($slug === '' || $db->selectOne('SELECT id FROM locations WHERE slug = :s', ['s' => $slug]) !== null) {
                        $slug = ($slug !== '' ? $slug : 'location') . '-' . $remoteId;
                    }
                    $locationMap[$remoteId] = $db->insert('locations', [
                        'name' => mb_substr($name, 0, 120), 'slug' => $slug, 'country' => 'IT',
                        'pterodactyl_location_id' => $remoteId, 'visible' => 1, 'maintenance' => 0, 'sort' => $remoteId,
                    ]);
                } else {
                    $locationMap[$remoteId] = (int) $local['id'];
                }
            }
            $added = 0;
            $updated = 0;
            foreach ($api->nodes() as $remote) {
                $remoteId = (int) ($remote['id'] ?? 0);
                $locationId = $locationMap[(int) ($remote['location_id'] ?? 0)] ?? null;
                if ($remoteId <= 0 || $locationId === null) {
                    continue;
                }
                $overallocate = (int) ($remote['memory_overallocate'] ?? 0);
                $data = [
                    'memory_mb' => (int) ($remote['memory'] ?? 0),
                    'disk_mb' => (int) ($remote['disk'] ?? 0),
                    'overallocation_pct' => $overallocate < 0 ? 100 : 100 + $overallocate,
                    'maintenance' => !empty($remote['maintenance_mode']) ? 1 : 0,
                ];
                $local = $db->selectOne('SELECT id FROM nodes WHERE pterodactyl_node_id = :p', ['p' => $remoteId]);
                if ($local === null) {
                    $db->insert('nodes', $data + [
                        'name' => mb_substr((string) ($remote['name'] ?? 'Node ' . $remoteId), 0, 120),
                        'location_id' => $locationId, 'pterodactyl_node_id' => $remoteId,
                        'cpu_percent' => 0, 'monthly_cost_cents' => 0, 'visible' => 1,
                    ]);
                    $added++;
                } else {
                    $db->update('nodes', $data + ['location_id' => $locationId], 'id = :id', ['id' => (int) $local['id']]);
                    $updated++;
                }
            }
            Audit::log('admin.nodes_synced', 'node', null, ['added' => $added, 'updated' => $updated]);
            flash('success', __('nodes_synced', ['added' => (string) $added, 'updated' => (string) $updated]));
        } catch (\Throwable $e) {
            flash('error', __('nodes_sync_failed', ['error' => $e->getMessage()]));
        }

        return Response::redirect($this->nodesUrl());
    }

    private function nodesUrl(): string
    {
        return url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/nodes');
    }

    public function topups(Request $request, array $params): Response
    {
        $this->require('invoices');
        $filter = $request->string('status', 'pending');
        if (!in_array($filter, ['pending', 'paid', 'rejected', 'all'], true)) {
            $filter = 'pending';
        }
        $db = App::db();
        $sql = 'SELECT t.*, u.username, u.email_enc FROM wallet_topups t JOIN users u ON u.id = t.user_id';
        $bind = [];
        if ($filter !== 'all') {
            $sql .= ' WHERE t.status = :status';
            $bind['status'] = $filter;
        }
        $rows = $db->select($sql . ' ORDER BY t.id DESC LIMIT 300', $bind);
        foreach ($rows as &$row) {
            $row['email'] = UserRepository::present($row)['email'];
        }
        unset($row);

        return Response::view('admin/topups', [
            'topups' => $rows,
            'filter' => $filter,
            'counts' => [
                'pending' => (int) $db->value('SELECT COUNT(*) FROM wallet_topups WHERE status = :s', ['s' => 'pending']),
                'pending_cents' => (int) $db->value('SELECT COALESCE(SUM(amount_cents), 0) FROM wallet_topups WHERE status = :s', ['s' => 'pending']),
            ],
            'bank' => (array) config('payments.bank', []),
            'cartCount' => 0,
        ]);
    }

    public function decideTopup(Request $request, array $params): Response
    {
        $this->require('invoices');
        $back = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/topups');
        $db = App::db();
        $topup = $db->selectOne('SELECT * FROM wallet_topups WHERE id = :id', ['id' => (int) ($params['id'] ?? 0)]);
        if ($topup === null) {
            throw new \App\Core\HttpException(404);
        }
        if ((string) $topup['status'] !== 'pending') {
            flash('error', __('topup_already_processed'));

            return Response::redirect($back);
        }
        $decision = $request->string('decision');
        $id = (int) $topup['id'];
        $userId = (int) $topup['user_id'];
        if ($decision === 'accept') {
            $amount = Money::parseInput($request->string('amount')) ?? (int) $topup['amount_cents'];
            if ($amount <= 0 || $amount > 1000000) {
                flash('error', __('invalid_amount'));

                return Response::redirect($back);
            }
            $credited = false;
            $db->transaction(static function (\App\Core\Database $db) use ($id, $userId, $amount, $topup, &$credited): void {
                // Only one admin can accept a pending top-up, even with two clicks.
                $changed = $db->execute(
                    'UPDATE wallet_topups SET status = :paid, amount_cents = :amount, paid_at = :now WHERE id = :id AND status = :pending',
                    ['paid' => 'paid', 'amount' => $amount, 'now' => Database::now(), 'id' => $id, 'pending' => 'pending']
                );
                if ($changed === 1) {
                    WalletService::credit($userId, $amount, 'topup', (string) $topup['reference'], ['topup_id' => $id, 'requested_cents' => (int) $topup['amount_cents']]);
                    $credited = true;
                }
            });
            if (!$credited) {
                flash('error', __('topup_already_processed'));

                return Response::redirect($back);
            }
            Audit::log('admin.topup_accepted', 'wallet_topup', $id, ['amount' => $amount, 'requested' => (int) $topup['amount_cents'], 'user' => $userId]);
            $this->notifyTopup($userId, 'accepted', (string) $topup['reference'], $amount, '');
            flash('success', __('topup_accepted', ['amount' => money($amount), 'reference' => (string) $topup['reference']]));
        } elseif ($decision === 'reject') {
            $reason = mb_substr($request->string('reason'), 0, 255);
            $db->execute('UPDATE wallet_topups SET status = :status WHERE id = :id AND status = :pending', ['status' => 'rejected', 'id' => $id, 'pending' => 'pending']);
            Audit::log('admin.topup_rejected', 'wallet_topup', $id, ['reason' => $reason, 'user' => $userId]);
            $this->notifyTopup($userId, 'rejected', (string) $topup['reference'], (int) $topup['amount_cents'], $reason);
            flash('success', __('topup_rejected_done', ['reference' => (string) $topup['reference']]));
        } else {
            throw new \App\Core\HttpException(422);
        }

        return Response::redirect($back);
    }

    /** E-mail to the customer about a processed top-up (best effort). */
    private function notifyTopup(int $userId, string $outcome, string $reference, int $amount, string $reason): void
    {
        try {
            $user = UserRepository::find($userId);
            $email = $user !== null ? UserRepository::present($user)['email'] : '';
            if ($email === '') {
                return;
            }
            $body = '<p>' . e(__('mail_topup_' . $outcome . '_body', ['reference' => $reference, 'amount' => money($amount)])) . '</p>'
                . ($reason !== '' ? '<p>' . e(__('reason')) . ': ' . e($reason) . '</p>' : '');
            \App\Core\Queue::push(\App\Domain\Jobs\SendMailJob::class, [
                'to' => \App\Core\Crypto::encrypt($email),
                'subject' => __('mail_topup_' . $outcome . '_subject', ['reference' => $reference]),
                'html' => \App\Core\Mailer::template(__('mail_topup_' . $outcome . '_subject', ['reference' => $reference]), $body, ['cta_url' => url('/client/wallet'), 'cta_label' => __('wallet')]),
                'template' => 'wallet_topup_' . $outcome,
            ]);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('topup.notify_failed', ['error' => $e->getMessage()]);
        }
    }

    public function creditWallet(Request $request, array $params): Response
    {
        $this->require('clients');
        $userId = (int) ($params['id'] ?? 0);
        $back = $request->string('back') === 'client'
            ? $this->clientUrl($userId)
            : url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/clients');
        $amount = Money::parseInput($request->string('amount')) ?? 0;
        if ($amount <= 0) {
            flash('error', __('invalid_amount'));

            return Response::redirect($back);
        }
        WalletService::credit($userId, $amount, 'admin_credit', 'admin:' . (int) Auth::id(), ['reason' => $request->string('reason')]);
        Audit::log('admin.wallet_credit', 'user', $userId, ['amount' => $amount]);
        flash('success', __('saved'));

        return Response::redirect($back);
    }

    public function reports(Request $request, array $params): Response
    {
        $db = App::db();
        $start = gmdate('Y-m-01 00:00:00', strtotime('-11 months'));
        $rows = $db->select(
            'SELECT substr(paid_at, 1, 7) AS ym, SUM(total_cents) AS total, COUNT(*) AS invoices
             FROM invoices WHERE status = :paid AND paid_at IS NOT NULL AND paid_at >= :start
             GROUP BY ym ORDER BY ym ASC',
            ['paid' => 'paid', 'start' => $start]
        );
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['ym']] = ['total' => (int) $row['total'], 'invoices' => (int) $row['invoices']];
        }
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = gmdate('Y-m', strtotime('-' . $i . ' months'));
            $months[] = [
                'key' => $key,
                'label' => gmdate('m/Y', strtotime($key . '-01')),
                'total' => $byMonth[$key]['total'] ?? 0,
                'invoices' => $byMonth[$key]['invoices'] ?? 0,
            ];
        }
        $maxMonth = max(1, max(array_column($months, 'total')));
        foreach ($months as &$month) {
            $month['width'] = (int) round($month['total'] / $maxMonth * 100);
        }
        unset($month);

        $mrr = 0;
        foreach ($db->select('SELECT price_cents, cycle FROM services WHERE status = :active', ['active' => 'active']) as $row) {
            $mrr += Money::divRound((int) $row['price_cents'], \App\Domain\PriceCalculator::months((string) $row['cycle']));
        }
        $activeServices = (int) $db->value('SELECT COUNT(*) FROM services WHERE status = :active', ['active' => 'active']);
        $activeCustomers = (int) $db->value('SELECT COUNT(DISTINCT user_id) FROM services WHERE status = :active', ['active' => 'active']);
        $arpu = $activeCustomers > 0 ? Money::divRound($mrr, $activeCustomers) : 0;
        $since30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $terminated30 = (int) $db->value('SELECT COUNT(*) FROM services WHERE terminated_at >= :since', ['since' => $since30]);
        $churnBase = $activeServices + $terminated30;
        $churnPct = $churnBase > 0 ? (int) round($terminated30 / $churnBase * 100) : 0;
        $orders30 = (int) $db->value('SELECT COUNT(*) FROM orders WHERE created_at >= :since', ['since' => $since30]);
        $paid30 = (int) $db->value('SELECT COUNT(*) FROM orders WHERE paid_at >= :since AND status IN (:paid, :provisioning, :active)', [
            'since' => $since30, 'paid' => 'paid', 'provisioning' => 'provisioning', 'active' => 'active',
        ]);
        $conversionPct = $orders30 > 0 ? (int) round($paid30 / $orders30 * 100) : 0;

        $byProduct = $db->select(
            'SELECT p.name, COUNT(oi.id) AS orders, SUM(oi.total_cents) AS total
             FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
             WHERE o.paid_at IS NOT NULL AND o.status IN (:paid, :provisioning, :active)
             GROUP BY p.id, p.name ORDER BY total DESC LIMIT 10',
            ['paid' => 'paid', 'provisioning' => 'provisioning', 'active' => 'active']
        );
        $byGateway = $db->select(
            'SELECT gateway, COUNT(*) AS payments, SUM(amount_cents) AS total
             FROM payments WHERE status = :succeeded GROUP BY gateway ORDER BY total DESC',
            ['succeeded' => 'succeeded']
        );
        $coupons = $db->select(
            'SELECT c.code, c.uses, COALESCE(SUM(r.amount_cents), 0) AS discount
             FROM coupons c LEFT JOIN coupon_redemptions r ON r.coupon_id = c.id
             GROUP BY c.id, c.code, c.uses ORDER BY c.uses DESC, discount DESC LIMIT 10'
        );

        $nodes = [];
        foreach ($db->select('SELECT n.*, l.name AS location_name FROM nodes n JOIN locations l ON l.id = n.location_id ORDER BY n.id ASC') as $node) {
            $revenue = 0;
            foreach ($db->select('SELECT price_cents, cycle FROM services WHERE node_id = :node AND status = :active', ['node' => (int) $node['id'], 'active' => 'active']) as $service) {
                $revenue += Money::divRound((int) $service['price_cents'], \App\Domain\PriceCalculator::months((string) $service['cycle']));
            }
            $nodes[] = [
                'name' => (string) $node['name'],
                'location' => (string) $node['location_name'],
                'revenue' => $revenue,
                'cost' => (int) $node['monthly_cost_cents'],
                'margin' => $revenue - (int) $node['monthly_cost_cents'],
            ];
        }

        return Response::view('admin/reports', [
            'months' => $months,
            'mrr' => $mrr,
            'arpu' => $arpu,
            'activeCustomers' => $activeCustomers,
            'activeServices' => $activeServices,
            'churnPct' => $churnPct,
            'terminated30' => $terminated30,
            'conversionPct' => $conversionPct,
            'orders30' => $orders30,
            'paid30' => $paid30,
            'byProduct' => $byProduct,
            'byGateway' => $byGateway,
            'coupons' => $coupons,
            'nodes' => $nodes,
        ]);
    }

    public function content(Request $request, array $params): Response
    {
        $db = App::db();

        return Response::view('admin/content', [
            'articles' => $db->select('SELECT * FROM kb_articles ORDER BY id DESC'),
            'announcements' => $db->select('SELECT * FROM announcements ORDER BY id DESC'),
        ]);
    }

    public function saveArticle(Request $request, array $params): Response
    {
        $base = '/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content';
        $title = trim($request->string('title'));
        $body = trim($request->string('body'));
        $category = trim($request->string('category'));
        if ($title === '' || $body === '' || $category === '') {
            flash('error', __('content_required'));

            return Response::redirect(url($base));
        }
        $slug = Str::slug($request->string('slug') !== '' ? $request->string('slug') : $title);
        if ($slug === '') {
            $slug = 'articolo-' . Str::random(4);
        }
        $candidate = $slug;
        for ($i = 2; App::db()->selectOne('SELECT id FROM kb_articles WHERE slug = :slug', ['slug' => $candidate]) !== null; $i++) {
            $candidate = $slug . '-' . $i;
        }
        $locale = $request->string('locale') === 'it' ? 'it' : 'en';
        $id = App::db()->insert('kb_articles', [
            'category' => mb_substr($category, 0, 80),
            'title' => mb_substr($title, 0, 190),
            'slug' => $candidate,
            'body' => $body,
            'locale' => $locale,
            'published' => $request->bool('published') ? 1 : 0,
        ]);
        Audit::log('kb.created', 'kb_article', $id, ['slug' => $candidate, 'locale' => $locale]);
        flash('success', __('article_created'));

        return Response::redirect(url($base));
    }

    public function editArticle(Request $request, array $params): Response
    {
        $article = App::db()->selectOne('SELECT * FROM kb_articles WHERE id = :id', ['id' => (int) ($params['id'] ?? 0)]);
        if ($article === null) {
            throw new \App\Core\HttpException(404);
        }

        return Response::view('admin/content_form', ['type' => 'article', 'item' => $article]);
    }

    public function updateArticle(Request $request, array $params): Response
    {
        $base = '/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content';
        $id = (int) ($params['id'] ?? 0);
        $article = App::db()->selectOne('SELECT * FROM kb_articles WHERE id = :id', ['id' => $id]);
        if ($article === null) {
            throw new \App\Core\HttpException(404);
        }
        $title = trim($request->string('title'));
        $body = trim($request->string('body'));
        $category = trim($request->string('category'));
        if ($title === '' || $body === '' || $category === '') {
            flash('error', __('content_required'));

            return Response::redirect(url($base . '/articles/' . $id . '/edit'));
        }
        $slug = Str::slug($request->string('slug') !== '' ? $request->string('slug') : $title);
        if ($slug === '') {
            $slug = 'articolo-' . $id;
        }
        $candidate = $slug;
        for ($i = 2; App::db()->selectOne('SELECT id FROM kb_articles WHERE slug = :slug AND id <> :id', ['slug' => $candidate, 'id' => $id]) !== null; $i++) {
            $candidate = $slug . '-' . $i;
        }
        App::db()->update('kb_articles', [
            'category' => mb_substr($category, 0, 80),
            'title' => mb_substr($title, 0, 190),
            'slug' => $candidate,
            'body' => $body,
            'locale' => $request->string('locale') === 'it' ? 'it' : 'en',
            'published' => $request->bool('published') ? 1 : 0,
        ], 'id = :id', ['id' => $id]);
        Audit::log('kb.updated', 'kb_article', $id, ['slug' => $candidate]);
        flash('success', __('article_updated'));

        return Response::redirect(url($base));
    }

    public function toggleArticle(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $article = App::db()->selectOne('SELECT * FROM kb_articles WHERE id = :id', ['id' => $id]);
        if ($article === null) {
            throw new \App\Core\HttpException(404);
        }
        $published = (int) $article['published'] === 1 ? 0 : 1;
        App::db()->update('kb_articles', ['published' => $published], 'id = :id', ['id' => $id]);
        Audit::log('kb.toggled', 'kb_article', $id, ['published' => $published]);

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content'));
    }

    public function saveAnnouncement(Request $request, array $params): Response
    {
        $base = '/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content';
        $title = trim($request->string('title'));
        $body = trim($request->string('body'));
        if ($title === '' || $body === '') {
            flash('error', __('content_required'));

            return Response::redirect(url($base));
        }
        $level = in_array($request->string('level'), ['info', 'success', 'warning', 'danger'], true) ? $request->string('level') : 'info';
        $id = App::db()->insert('announcements', [
            'title' => mb_substr($title, 0, 190),
            'body' => $body,
            'level' => $level,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => self::utcDate($request->string('expires_at'), ' 23:59:59'),
            'visible' => 1,
        ]);
        Audit::log('announcement.created', 'announcement', $id, ['level' => $level]);
        flash('success', __('announcement_created'));

        return Response::redirect(url($base));
    }

    public function editAnnouncement(Request $request, array $params): Response
    {
        $announcement = App::db()->selectOne('SELECT * FROM announcements WHERE id = :id', ['id' => (int) ($params['id'] ?? 0)]);
        if ($announcement === null) {
            throw new \App\Core\HttpException(404);
        }

        return Response::view('admin/content_form', ['type' => 'announcement', 'item' => $announcement]);
    }

    public function updateAnnouncement(Request $request, array $params): Response
    {
        $base = '/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content';
        $id = (int) ($params['id'] ?? 0);
        $announcement = App::db()->selectOne('SELECT * FROM announcements WHERE id = :id', ['id' => $id]);
        if ($announcement === null) {
            throw new \App\Core\HttpException(404);
        }
        $title = trim($request->string('title'));
        $body = trim($request->string('body'));
        if ($title === '' || $body === '') {
            flash('error', __('content_required'));

            return Response::redirect(url($base . '/announcements/' . $id . '/edit'));
        }
        $level = in_array($request->string('level'), ['info', 'success', 'warning', 'danger'], true) ? $request->string('level') : 'info';
        App::db()->update('announcements', [
            'title' => mb_substr($title, 0, 190),
            'body' => $body,
            'level' => $level,
            'expires_at' => self::utcDate($request->string('expires_at'), ' 23:59:59'),
        ], 'id = :id', ['id' => $id]);
        Audit::log('announcement.updated', 'announcement', $id, ['level' => $level]);
        flash('success', __('announcement_updated'));

        return Response::redirect(url($base));
    }

    public function toggleAnnouncement(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $announcement = App::db()->selectOne('SELECT * FROM announcements WHERE id = :id', ['id' => $id]);
        if ($announcement === null) {
            throw new \App\Core\HttpException(404);
        }
        $visible = (int) $announcement['visible'] === 1 ? 0 : 1;
        App::db()->update('announcements', ['visible' => $visible], 'id = :id', ['id' => $id]);
        Audit::log('announcement.toggled', 'announcement', $id, ['visible' => $visible]);

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content'));
    }

    public function tickets(Request $request, array $params): Response
    {
        $status = $request->string('status');
        $department = $request->string('department');
        $sql = 'SELECT t.*, u.username FROM tickets t JOIN users u ON u.id = t.user_id WHERE 1 = 1';
        $bind = [];
        if (in_array($status, ['open', 'waiting_staff', 'waiting_customer', 'closed'], true)) {
            $sql .= ' AND t.status = :status';
            $bind['status'] = $status;
        }
        if (in_array($department, ['technical', 'billing', 'sales'], true)) {
            $sql .= ' AND t.department = :department';
            $bind['department'] = $department;
        }
        $sql .= ' ORDER BY t.updated_at DESC, t.id DESC';
        $tickets = App::db()->select($sql, $bind);

        return Response::view('admin/tickets', [
            'tickets' => $tickets,
            'status' => $status,
            'department' => $department,
        ]);
    }

    public function ticket(Request $request, array $params): Response
    {
        $ticket = TicketService::find((int) ($params['id'] ?? 0));
        if ($ticket === null) {
            throw new \App\Core\HttpException(404);
        }
        $customer = UserRepository::find((int) $ticket['user_id']);

        return Response::view('admin/ticket', [
            'ticket' => $ticket,
            'messages' => TicketService::messages((int) $ticket['id'], true),
            'attachments' => Attachments::forTicket((int) $ticket['id']),
            'customer' => UserRepository::present($customer ?? []),
            'username' => (string) ($customer['username'] ?? ''),
        ]);
    }

    public function replyTicket(Request $request, array $params): Response
    {
        $ticket = TicketService::find((int) ($params['id'] ?? 0));
        if ($ticket === null) {
            throw new \App\Core\HttpException(404);
        }
        $body = $request->string('body');
        if ($body !== '') {
            TicketService::reply((int) $ticket['id'], (int) Auth::id(), $body, $request->bool('internal'));
            self::storeAttachment($request, (int) $ticket['id']);
            flash('success', __('reply_sent'));
        }

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/tickets/' . (int) $ticket['id']));
    }

    public function downloadAttachment(Request $request, array $params): Response
    {
        $attachment = Attachments::find((int) ($params['id'] ?? 0));
        if ($attachment === null) {
            throw new \App\Core\HttpException(404);
        }
        $path = Attachments::absolutePath($attachment);
        if (!is_file($path)) {
            throw new \App\Core\HttpException(404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => (string) $attachment['mime'],
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . addcslashes((string) $attachment['filename'], "\"\\") . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param Request $request */
    private static function storeAttachment(Request $request, int $ticketId): void
    {
        $file = $request->file('attachment');
        if ($file === []) {
            return;
        }
        try {
            Attachments::store($ticketId, TicketService::latestMessageId($ticketId), $file);
        } catch (\App\Core\HttpException $e) {
            flash('warning', $e->getMessage());
        }
    }

    public function closeTicket(Request $request, array $params): Response
    {
        $ticket = TicketService::find((int) ($params['id'] ?? 0));
        if ($ticket === null) {
            throw new \App\Core\HttpException(404);
        }
        TicketService::close((int) $ticket['id'], null, (int) Auth::id());
        flash('success', __('ticket_closed_by_staff'));

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/tickets/' . (int) $ticket['id']));
    }

    public function coupons(Request $request, array $params): Response
    {
        $coupons = App::db()->select(
            'SELECT c.*, (SELECT COUNT(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id) AS redemptions
             FROM coupons c ORDER BY c.id DESC'
        );

        return Response::view('admin/coupons', ['coupons' => $coupons]);
    }

    public function saveCoupon(Request $request, array $params): Response
    {
        $base = '/' . trim((string) config('security.admin_path', 'admin'), '/') . '/coupons';
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', $request->string('code')));
        if (strlen($code) < 3 || strlen($code) > 40) {
            flash('error', __('coupon_invalid_code'));

            return Response::redirect(url($base));
        }
        if (App::db()->selectOne('SELECT id FROM coupons WHERE code = :code', ['code' => $code]) !== null) {
            flash('error', __('coupon_exists'));

            return Response::redirect(url($base));
        }

        $type = $request->string('type') === 'fixed' ? 'fixed' : 'percent';
        if ($type === 'percent') {
            $value = (int) $request->string('value');
            $valid = $value >= 1 && $value <= 100;
        } else {
            $value = Money::parseInput($request->string('value')) ?? 0;
            $valid = $value > 0;
        }
        if (!$valid) {
            flash('error', __('coupon_invalid_value'));

            return Response::redirect(url($base));
        }

        $appliesTo = [];
        foreach (preg_split('/[^0-9]+/', $request->string('applies_to')) ?: [] as $id) {
            if ((int) $id > 0) {
                $appliesTo[] = (int) $id;
            }
        }

        $id = App::db()->insert('coupons', [
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'valid_from' => self::utcDate($request->string('valid_from'), ' 00:00:00'),
            'valid_to' => self::utcDate($request->string('valid_to'), ' 23:59:59'),
            'max_uses' => max(0, $request->int('max_uses')),
            'per_user_limit' => max(1, $request->int('per_user_limit', 1)),
            'first_purchase_only' => $request->bool('first_purchase_only') ? 1 : 0,
            'applies_to_json' => $appliesTo === [] ? null : Json::encode($appliesTo),
            'active' => 1,
        ]);
        Audit::log('coupon.created', 'coupon', $id, ['code' => $code, 'type' => $type, 'value' => $value]);
        flash('success', __('coupon_created'));

        return Response::redirect(url($base));
    }

    public function toggleCoupon(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $coupon = App::db()->selectOne('SELECT * FROM coupons WHERE id = :id', ['id' => $id]);
        if ($coupon === null) {
            throw new \App\Core\HttpException(404);
        }
        $active = (int) $coupon['active'] === 1 ? 0 : 1;
        App::db()->update('coupons', ['active' => $active], 'id = :id', ['id' => $id]);
        Audit::log('coupon.toggled', 'coupon', $id, ['active' => $active]);

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/coupons'));
    }

    public function deleteCoupon(Request $request, array $params): Response
    {
        $base = '/' . trim((string) config('security.admin_path', 'admin'), '/') . '/coupons';
        $id = (int) ($params['id'] ?? 0);
        $coupon = App::db()->selectOne('SELECT * FROM coupons WHERE id = :id', ['id' => $id]);
        if ($coupon === null) {
            throw new \App\Core\HttpException(404);
        }
        $redemptions = (int) App::db()->value('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :id', ['id' => $id]);
        if ($redemptions > 0) {
            flash('error', __('coupon_has_redemptions'));

            return Response::redirect(url($base));
        }
        App::db()->execute('DELETE FROM coupons WHERE id = :id', ['id' => $id]);
        Audit::log('coupon.deleted', 'coupon', $id, ['code' => $coupon['code']]);
        flash('success', __('coupon_deleted'));

        return Response::redirect(url($base));
    }

    /** Returns a UTC datetime only for a valid Y-m-d input. */
    private static function utcDate(string $date, string $time): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . $time, new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->format('Y-m-d H:i:s');
    }

    public function settings(Request $request, array $params): Response
    {
        $this->require('settings');

        return Response::view('admin/settings', [
            'settings' => App::settings(),
            'license' => LicenseClient::state(),
            'featureFlags' => App::db()->select('SELECT * FROM feature_flags ORDER BY name ASC'),
            'cartCount' => 0,
        ]);
    }

    public function activateLicense(Request $request, array $params): Response
    {
        $this->require('settings');
        $back = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/settings#license');
        $key = trim($request->string('license_key'));
        $server = trim($request->string('license_server'));
        if ($key === '') {
            flash('error', __('license_key_required'));

            return Response::redirect($back);
        }
        if ($server !== '' && !preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $server)) {
            flash('error', __('license_server_invalid'));

            return Response::redirect($back);
        }
        $publicKey = trim($request->string('license_public_key'));
        if ($publicKey !== '') {
            if (!LicenseClient::validPublicKey($publicKey)) {
                flash('error', __('license_public_key_invalid'));

                return Response::redirect($back);
            }
            App::setSetting('license_public_key', $publicKey);
        }
        if (!LicenseClient::hasPublicKey()) {
            flash('error', __('license_public_key_needed'));

            return Response::redirect($back);
        }
        if ($server === '' && LicenseClient::serverUrl() === '') {
            flash('error', __('license_server_needed'));

            return Response::redirect($back);
        }
        try {
            LicenseClient::activate($key, $server !== '' ? $server : null);
            Audit::log('admin.license_activated', 'license', null, ['status' => LicenseClient::status()]);
            flash('success', __('license_activated'));
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('license.activation_failed', ['error' => $e->getMessage()]);
            flash('error', __('license_error') . ' ' . LicenseClient::explain($e->getMessage()));
        }

        return Response::redirect($back);
    }

    public function checkLicense(Request $request, array $params): Response
    {
        $this->require('settings');
        $result = LicenseClient::check(true);
        $status = LicenseClient::status();
        if (LicenseClient::isValid() && $status !== 'grace') {
            flash('success', __('license_check_ok', ['status' => __('license_status_' . $status)]));
        } else {
            flash('error', __('license_check_failed', ['status' => __('license_status_' . $status), 'reason' => LicenseClient::explain((string) ($result['error'] ?? '—'))]));
        }

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/settings#license'));
    }

    public function saveSettings(Request $request, array $params): Response
    {
        $this->require('settings');
        $keys = [
            'company_name', 'company_vat', 'company_address', 'company_city',
            'company_country', 'company_email', 'invoice_footer', 'mail_footer',
        ];
        foreach ($keys as $key) {
            App::setSetting($key, $request->string($key));
        }
        foreach (['renew_notice_days', 'grace_days', 'terminate_after_days', 'risk_review_threshold', 'log_retention_days'] as $key) {
            App::setSetting($key, $request->int($key));
        }
        foreach (['enforce_admin_2fa', 'maintenance_mode', 'auto_charge_wallet'] as $key) {
            App::setSetting($key, $request->bool($key));
        }
        Audit::log('admin.settings_updated', 'settings', null, []);
        flash('success', __('saved'));

        return Response::redirect(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/settings'));
    }

    public function appearance(Request $request, array $params): Response
    {
        $this->require('settings');

        return Response::view('admin/appearance', [
            'appearance' => \App\Domain\Theme::stored(),
            'cartCount' => 0,
        ]);
    }

    public function saveAppearance(Request $request, array $params): Response
    {
        $this->require('settings');
        $back = url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/appearance');
        if (($locked = $this->lockedWithoutLicense($back)) !== null) {
            return $locked;
        }
        $current = \App\Domain\Theme::stored();

        if ($request->bool('reset_appearance')) {
            foreach (\App\Domain\Theme::defaults() as $key => $default) {
                App::setSetting($key, $default);
            }
            Audit::log('admin.appearance_reset', 'settings', null, []);
            flash('success', __('appearance_reset_done'));

            return Response::redirect($back);
        }

        $input = [
            'theme_mode' => $request->string('theme_mode'),
            'theme_toggle' => $request->bool('theme_toggle'),
            'brand_color' => $request->string('brand_color'),
            'accent_color' => $request->string('accent_color'),
            'font_family' => $request->string('font_family'),
            'radius' => $request->string('radius'),
            'density' => $request->string('density'),
            'background_style' => $request->string('background_style'),
            'layout_width' => $request->string('layout_width'),
            'logo_data' => $current['logo_data'],
            'logo_height' => $request->int('logo_height', 34),
            'favicon_data' => $current['favicon_data'],
            'show_brand_name' => $request->bool('show_brand_name'),
            'hero_badge' => $request->string('hero_badge'),
            'hero_title' => $request->string('hero_title'),
            'hero_lead' => $request->string('hero_lead'),
            'hero_cta' => $request->string('hero_cta'),
            'topbar_text' => $request->string('topbar_text'),
            'topbar_link' => $request->string('topbar_link'),
            'footer_text' => $request->string('footer_text'),
            'custom_css' => is_string($request->input('custom_css')) ? (string) $request->input('custom_css') : '',
            'home_sections' => $request->array('home_sections'),
        ];
        try {
            foreach (['logo' => 'logo_data', 'favicon' => 'favicon_data'] as $field => $key) {
                if ($request->bool('remove_' . $field)) {
                    $input[$key] = '';
                }
                $uploaded = \App\Domain\Theme::imageFromUpload($request->file($field));
                if ($uploaded !== null) {
                    $input[$key] = $uploaded;
                }
            }
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());

            return Response::redirect($back);
        }

        foreach (\App\Domain\Theme::normalize($input) as $key => $value) {
            App::setSetting($key, $value);
        }
        $brandName = trim($request->string('brand_name'));
        if ($brandName !== '') {
            App::setSetting('brand_name', mb_substr($brandName, 0, 80));
        }
        Audit::log('admin.appearance_updated', 'settings', null, []);
        flash('success', __('saved'));

        return Response::redirect($back);
    }

    public function logs(Request $request, array $params): Response
    {
        $this->require('logs');
        $db = App::db();

        return Response::view('admin/logs', [
            'audit' => $db->select('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 100'),
            'provisioning' => $db->select('SELECT * FROM provisioning_logs ORDER BY id DESC LIMIT 50'),
            'webhooks' => $db->select('SELECT * FROM webhook_events ORDER BY id DESC LIMIT 30'),
            'mails' => $db->select('SELECT * FROM mail_log ORDER BY id DESC LIMIT 30'),
            'jobs' => $db->select('SELECT * FROM jobs WHERE failed_at IS NOT NULL ORDER BY id DESC LIMIT 30'),
            'cartCount' => 0,
        ]);
    }

    public function diagnostics(Request $request, array $params): Response
    {
        $this->require('pterodactyl');

        return Response::view('admin/diagnostics', [
            'pterodactyl' => ProvisioningService::diagnostics(),
            'license' => LicenseClient::state(),
            'address' => app_address() + [
                'url' => (string) config('app.url', ''),
                'request_https' => request_is_https(),
                'request_host' => strtolower(trim((string) ($request->header('Host') ?? ''), '[]')),
                'cookie_secure' => (bool) config('session.secure', false),
            ],
            'queuePending' => Queue::pendingCount(),
            'queueFailed' => Queue::failedCount(),
            'nodes' => App::db()->select('SELECT n.*, l.name AS location_name FROM nodes n JOIN locations l ON l.id = n.location_id ORDER BY n.id ASC'),
            'cartCount' => 0,
        ]);
    }

    private function require(string $permission): void
    {
        if (!Auth::can($permission)) {
            throw new \App\Core\HttpException(403, __('forbidden'));
        }
    }
}
