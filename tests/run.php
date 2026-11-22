<?php

declare(strict_types=1);

/**
 * Dependency-free test-suite:
 *
 *   php tests/run.php
 *
 * Covers the money/tax invariants, the configurator maths, both state
 * machines, coupons, TOTP, crypto, the PDF writer, the Stripe webhook
 * signature and an in-memory SQLite schema smoke test.
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Hasher;
use App\Core\Json;
use App\Core\Money;
use App\Core\PdfDocument;
use App\Core\Request;
use App\Core\Schema;
use App\Core\Totp;
use App\Core\Vat;
use App\Domain\Coupons;
use App\Domain\Gateways\PayPalGateway;
use App\Domain\Gateways\StripeGateway;
use App\Domain\OrderStateMachine;
use App\Domain\Pterodactyl\ClientClient;
use App\Domain\PriceCalculator;
use App\Domain\ProvisioningService;
use App\Domain\ServiceStateMachine;
use App\Domain\Services;
use App\Domain\UserRepository;

// The suite must run without a .env (fresh checkout): throwaway keys for this process only.
foreach (['APP_KEY', 'BLIND_INDEX_KEY'] as $testKey) {
    if (getenv($testKey) === false) {
        putenv($testKey . '=' . base64_encode(random_bytes(32)));
    }
}
App::boot(dirname(__DIR__));

$passed = 0;
$failed = 0;
$failures = [];

function test(string $name, callable $callback): void
{
    global $passed, $failed, $failures;
    try {
        $callback();
        $passed++;
        fwrite(STDOUT, "  ok   $name\n");
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $name . ': ' . $e->getMessage();
        fwrite(STDOUT, "  FAIL $name -> " . $e->getMessage() . "\n");
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $callback, string $message = 'expected exception'): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

// ---------------------------------------------------------------------------
// Money and VAT
// ---------------------------------------------------------------------------

test('Money formats cents with Italian separators', function (): void {
    assertSame('1.234,56 €', Money::format(123456));
    assertSame('0,05 €', Money::format(5));
    assertSame('-12,00 €', Money::format(-1200));
    assertSame('12,00 $', Money::format(1200, 'USD'));
});

test('Money rounds half up and never uses floats', function (): void {
    assertSame(3, Money::divRound(5, 2));
    assertSame(-3, Money::divRound(-5, 2));
    assertSame(2, Money::divRound(5, 3));
    assertSame(200, Money::applyRate(1000, 2000));
});

test('VAT is extracted from the gross price', function (): void {
    assertSame(2200, Money::vatFromGross(12200, 2200));
    assertSame(10000, Money::netFromGross(12200, 2200));
    assertSame(0, Money::vatFromGross(1000, 0));
    assertSame(12200, Money::grossFromNet(10000, 2200));
});

test('Prorated amounts clamp to the billing period', function (): void {
    assertSame(1500, Money::prorata(3000, 15, 30));
    assertSame(3000, Money::prorata(3000, 45, 30));
    assertSame(0, Money::prorata(3000, 0, 30));
    assertSame(0, Money::prorata(3000, 5, 0));
});

test('VAT treatment handles domestic, reverse charge, OSS and export', function (): void {
    $domestic = Vat::treatment('IT', 'IT', false, false);
    assertSame('domestic', $domestic['mode']);
    assertSame(2200, $domestic['rate_bp']);

    $reverse = Vat::treatment('IT', 'DE', true, true);
    assertSame('reverse_charge', $reverse['mode']);
    assertSame(0, $reverse['rate_bp']);
    assertTrue($reverse['reverse_charge']);

    $oss = Vat::treatment('IT', 'DE', false, false);
    assertSame('oss', $oss['mode']);
    assertSame(1900, $oss['rate_bp']);

    $export = Vat::treatment('IT', 'US', false, false);
    assertSame('export', $export['mode']);
    assertSame(0, $export['rate_bp']);
});

// ---------------------------------------------------------------------------
// Configurator
// ---------------------------------------------------------------------------

$product = [
    'id' => 1,
    'name' => 'Test',
    'prices' => ['monthly' => ['price_cents' => 1000], 'annual' => ['price_cents' => 10000]],
    'options' => [
        'ram' => ['min_value' => 1024, 'max_value' => 8192, 'step' => 1024, 'included' => 1024, 'unit_cents' => 200],
        'cpu' => ['min_value' => 100, 'max_value' => 400, 'step' => 100, 'included' => 100, 'unit_cents' => 100],
        'disk' => ['min_value' => 10240, 'max_value' => 51200, 'step' => 10240, 'included' => 10240, 'unit_cents' => 50],
    ],
    'addons' => [['id' => 5, 'name' => 'Backup', 'price_cents' => 300]],
];

$selection = ['ram_mb' => 2048, 'cpu_percent' => 200, 'disk_mb' => 20480, 'version' => '1.21.8', 'software' => 'paper', 'location_id' => 1];

test('Configurator scales resources and add-ons', function () use ($product, $selection): void {
    $quote = PriceCalculator::quote($product, $selection, [5], 'monthly');
    assertSame(1000, $quote['base_cents']);
    assertSame(200, $quote['resources'][0]['amount_cents']);
    assertSame(100, $quote['resources'][1]['amount_cents']);
    assertSame(50, $quote['resources'][2]['amount_cents']);
    assertSame(300, $quote['addons'][0]['amount_cents']);
    assertSame(1650, $quote['subtotal_cents']);
    assertSame(1650, $quote['total_cents']);
    assertSame(2048, $quote['config']['ram_mb']);
});

test('Annual cycle applies the yearly price and scales extras', function () use ($product, $selection): void {
    $quote = PriceCalculator::quote($product, $selection, [5], 'annual');
    assertSame(10000, $quote['base_cents']);
    assertSame(16500, $quote['total_cents']);
    assertSame(12, $quote['months']);
});

test('Configurator clamps out-of-range values to the steps', function () use ($product): void {
    $quote = PriceCalculator::quote($product, ['ram_mb' => 99999, 'cpu_percent' => 1, 'disk_mb' => 1], [], 'monthly');
    assertSame(8192, $quote['config']['ram_mb']);
    assertSame(100, $quote['config']['cpu_percent']);
    assertSame(10240, $quote['config']['disk_mb']);
});

test('Coupons compute percentage and fixed discounts', function (): void {
    assertSame(100, Coupons::discount(['type' => 'percent', 'value' => 10], 1000));
    assertSame(250, Coupons::discount(['type' => 'fixed', 'value' => 250], 1000));
    assertSame(0, Coupons::discount(['type' => 'unknown', 'value' => 250], 1000));
});

// ---------------------------------------------------------------------------
// State machines
// ---------------------------------------------------------------------------

test('Service state machine only allows declared transitions', function (): void {
    assertTrue(ServiceStateMachine::can('pending', 'installing'));
    assertTrue(ServiceStateMachine::can('active', 'suspended'));
    assertTrue(ServiceStateMachine::can('suspended', 'active'));
    assertTrue(!ServiceStateMachine::can('terminated', 'active'));
    assertTrue(!ServiceStateMachine::can('active', 'pending'));
    assertThrows(static fn () => ServiceStateMachine::assert('terminated', 'active'));
});

test('Order state machine only allows declared transitions', function (): void {
    assertTrue(OrderStateMachine::can('pending_payment', 'paid'));
    assertTrue(OrderStateMachine::can('paid', 'provisioning'));
    assertTrue(!OrderStateMachine::can('cancelled', 'paid'));
    assertThrows(static fn () => OrderStateMachine::assert('refunded', 'active'));
});

// ---------------------------------------------------------------------------
// Crypto, TOTP, hashing
// ---------------------------------------------------------------------------

test('TOTP generates and verifies six-digit codes', function (): void {
    $secret = Totp::secret();
    $code = Totp::code($secret);
    assertSame(6, strlen($code));
    assertTrue(Totp::verify($secret, $code));
    assertTrue(!Totp::verify($secret, '000000') || $code === '000000');
    assertTrue(str_starts_with(Totp::uri($secret, 'demo@example.com', 'Test'), 'otpauth://totp/'));
});

test('Password hashing verifies and rejects wrong passwords', function (): void {
    $hash = Hasher::hash('correct horse battery staple');
    assertTrue(Hasher::verify('correct horse battery staple', $hash));
    assertTrue(!Hasher::verify('wrong password', $hash));
});

// ---------------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------------

test('PDF writer emits a valid document envelope', function (): void {
    $pdf = new PdfDocument('Fattura 1/2026');
    $pdf->text(40, 60, 'Fattura n. 1/2026', 16, true);
    $pdf->text(40, 90, 'Totale: 1.234,56 €');
    $pdf->rect(40, 100, 200, 20);
    $output = $pdf->output();
    assertTrue(str_starts_with($output, '%PDF-1.4'));
    assertTrue(str_ends_with($output, "%%EOF\n"));
    assertTrue(str_contains($output, 'startxref'));
    assertTrue(str_contains($output, 'Helvetica'));
});

// ---------------------------------------------------------------------------
// Gateway integrations
// ---------------------------------------------------------------------------

test('Stripe webhook accepts a correctly signed payment', function (): void {
    $secret = 'whsec_test_secret';
    $payload = json_encode([
        'id' => 'evt_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_1',
            'payment_status' => 'paid',
            'amount_total' => 1650,
            'metadata' => ['order_id' => '42', 'invoice_id' => '7'],
            'payment_intent' => 'pi_1',
        ]],
    ], JSON_UNESCAPED_SLASHES);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    $request = new Request(
        'POST',
        '/webhook/stripe',
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => 't=' . $timestamp . ',v1=' . $signature],
        [],
        $payload
    );
    $gateway = new StripeGateway(['secret' => 'sk_test', 'webhook_secret' => $secret]);
    $event = $gateway->handleWebhook($request);
    assertSame(42, $event['order_id']);
    assertSame(1650, $event['amount_cents']);
    assertSame('succeeded', $event['status']);
});

test('Stripe webhook rejects a forged signature', function (): void {
    $payload = '{"id":"evt_2","type":"checkout.session.completed"}';
    $request = new Request('POST', '/webhook/stripe', [], [], ['HTTP_STRIPE_SIGNATURE' => 't=' . time() . ',v1=deadbeef'], [], $payload);
    $gateway = new StripeGateway(['secret' => 'sk_test', 'webhook_secret' => 'whsec_test_secret']);
    assertThrows(static fn () => $gateway->handleWebhook($request));
});

test('PayPal amounts convert without rounding errors', function (): void {
    assertSame('19.99', PayPalGateway::amount(1999));
    assertSame(1999, PayPalGateway::cents('19.99'));
    assertSame(1000, PayPalGateway::cents('10.00'));
});

// ---------------------------------------------------------------------------
// Schema and settings (SQLite in memory)
// ---------------------------------------------------------------------------

test('Schema installs every table on SQLite and is idempotent', function (): void {
    $db = new Database(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
    App::setDatabase($db);
    Schema::install($db);
    Schema::install($db);
    $tables = $db->select("SELECT name FROM sqlite_master WHERE type = 'table'");
    $names = array_column($tables, 'name');
    foreach (['users', 'products', 'services', 'orders', 'invoices', 'payments', 'tickets', 'audit_logs', 'jobs', 'settings'] as $expected) {
        assertTrue(in_array($expected, $names, true), 'missing table ' . $expected);
    }
    assertTrue(count($names) >= 40, 'expected at least 40 tables, got ' . count($names));
});

test('Settings keep their scalar type through JSON storage', function (): void {
    App::setSetting('brand_name', 'Blockwave');
    App::setSetting('enforce_license', true);
    App::setSetting('risk_review_threshold', 70);
    assertSame('Blockwave', App::setting('brand_name'));
    assertSame(true, App::setting('enforce_license'));
    assertSame(70, App::setting('risk_review_threshold'));
    assertSame('x', Json::value('"x"'));
    assertSame(null, Json::value(''));
});

// ---------------------------------------------------------------------------
// Pterodactyl integration against a mocked Application API
// ---------------------------------------------------------------------------

function mockStatePath(): string
{
    return sys_get_temp_dir() . '/mock-pterodactyl.json';
}

/** @return array{proc:resource|null,base:string}|null */
function startMockPanel(): ?array
{
    $base = 'http://127.0.0.1:8093';
    if (@file_get_contents($base . '/__health') !== false) {
        return ['proc' => null, 'base' => $base];
    }
    @unlink(mockStatePath());
    $log = App::path('storage/logs/mock-pterodactyl.log');
    $command = [PHP_BINARY, '-S', '127.0.0.1:8093', __DIR__ . '/fixtures/pterodactyl-router.php'];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
    $proc = proc_open($command, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return null;
    }
    if (isset($pipes[0])) {
        fclose($pipes[0]);
    }
    $ready = false;
    for ($attempt = 0; $attempt < 80; $attempt++) {
        usleep(100000);
        if (@file_get_contents($base . '/__health') !== false) {
            $ready = true;
            break;
        }
    }
    if (!$ready) {
        proc_terminate($proc, 9);
        proc_close($proc);

        return null;
    }

    return ['proc' => $proc, 'base' => $base];
}

/** @param array{proc:resource|null,base:string}|null $panel */
function stopMockPanel(?array $panel): void
{
    if ($panel === null || !is_resource($panel['proc'])) {
        return;
    }
    proc_terminate($panel['proc'], 9);
    proc_close($panel['proc']);
}

test('Provisioning lifecycle against a mocked Pterodactyl API', function (): void {
    $panel = startMockPanel();
    assertTrue($panel !== null, 'mock Pterodactyl server did not start');
    try {
        $db = new Database(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
        App::setDatabase($db);
        Schema::install($db);
        Config::merge(['pterodactyl' => [
            'url' => $panel['base'],
            'application_key' => 'test-key',
            'timeout' => 5,
            'retries' => 1,
        ]]);

        $user = UserRepository::create('paneluser', 'panel@example.com', 'Password123!');
        $categoryId = $db->insert('categories', ['name' => 'Minecraft', 'slug' => 'minecraft']);
        $locationId = $db->insert('locations', ['name' => 'Milan', 'slug' => 'milan', 'country' => 'IT']);
        $nodeId = $db->insert('nodes', [
            'name' => 'node-1', 'location_id' => $locationId, 'pterodactyl_node_id' => 1,
            'memory_mb' => 16384, 'disk_mb' => 100000, 'overallocation_pct' => 100,
            'maintenance' => 0, 'visible' => 1,
        ]);
        $productId = $db->insert('products', [
            'category_id' => $categoryId, 'name' => 'Test Stone', 'slug' => 'test-stone',
            'tier' => 'budget', 'visible' => 1, 'nest_id' => 1, 'egg_id' => 1,
            'min_ram_mb' => 1024, 'max_ram_mb' => 8192, 'ram_step_mb' => 1024,
            'allowed_versions_json' => json_encode(['1.21.8']),
            'meta_json' => json_encode(['software' => 'paper']),
            'created_at' => Database::now(),
        ]);
        $serviceId = $db->insert('services', [
            'user_id' => (int) $user['id'], 'product_id' => $productId, 'name' => 'Test Stone · 2 GB',
            'status' => 'pending', 'cycle' => 'monthly', 'price_cents' => 1599, 'currency' => 'EUR',
            'ram_mb' => 2048, 'cpu_percent' => 100, 'disk_mb' => 10240, 'location_id' => $locationId,
            'config_json' => json_encode(['ram_mb' => 2048, 'cpu_percent' => 100, 'disk_mb' => 10240, 'version' => '1.21.8', 'software' => 'paper']),
            'provision_key' => hash('sha256', 'test-service'), 'provisioning_state' => 'queued',
            'next_due_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30), 'created_at' => Database::now(),
        ]);

        ProvisioningService::provision($serviceId);
        $service = Services::find($serviceId);
        assertSame(55, (int) $service['pterodactyl_server_id']);
        assertSame('srv55', (string) $service['external_identifier']);
        assertSame('installing', (string) $service['status']);
        assertSame('installing', (string) $service['provisioning_state']);
        assertSame('192.0.2.10', (string) $service['ip']);
        assertSame(25565, (int) $service['port']);

        $state = json_decode((string) file_get_contents(mockStatePath()), true);
        assertSame(1, $state['users']);
        assertSame(1, $state['created']);
        assertSame(2048, $state['last_server']['limits']['memory']);
        assertSame(100, $state['last_server']['limits']['cpu']);
        assertSame(10240, $state['last_server']['limits']['disk']);
        assertSame(11, $state['last_server']['allocation']['default']);
        assertSame('1.21.8', $state['last_server']['environment']['MINECRAFT_VERSION']);
        assertSame('paper.jar', $state['last_server']['environment']['SERVER_JARFILE']);

        // A retry must update the existing server, never create a second one.
        ProvisioningService::provision($serviceId);
        $state = json_decode((string) file_get_contents(mockStatePath()), true);
        assertSame(1, $state['created']);
        assertSame(1, $state['updated']);

        // The installation poll sees a running server and activates the service.
        ProvisioningService::checkInstall($serviceId);
        $service = Services::find($serviceId);
        assertSame('active', (string) $service['status']);
        assertSame('ready', (string) $service['provisioning_state']);

        $reconcile = ProvisioningService::reconcile();
        assertSame([], $reconcile['orphans']);
        assertSame([], $reconcile['missing']);

        ProvisioningService::suspend($service);
        $state = json_decode((string) file_get_contents(mockStatePath()), true);
        assertSame(1, $state['suspended']);
        assertSame('suspended', (string) Services::find($serviceId)['status']);

        ProvisioningService::terminate(Services::find($serviceId));
        $state = json_decode((string) file_get_contents(mockStatePath()), true);
        assertSame(1, $state['deleted']);
        assertSame('terminated', (string) Services::find($serviceId)['status']);
    } finally {
        stopMockPanel($panel);
    }
});

test('Client websocket credentials are unwrapped for the console', function (): void {
    $panel = startMockPanel();
    assertTrue($panel !== null, 'mock Pterodactyl server did not start');
    try {
        $client = new ClientClient($panel['base'], 'test-key', ['timeout' => 5, 'retries' => 1]);
        $credentials = $client->websocket('test');
        assertSame('wss://panel.example.test/api/client/servers/test/websocket', (string) ($credentials['socket'] ?? ''));
        assertSame('test-websocket-token', (string) ($credentials['token'] ?? ''));
    } finally {
        stopMockPanel($panel);
    }
});

test('HTTPS is detected from the server, X-Forwarded-Proto only behind a trusted proxy', function (): void {
    putenv('TRUST_PROXY=false');
    assertTrue(request_is_https(['HTTPS' => 'on']), 'HTTPS=on');
    assertTrue(!request_is_https(['HTTPS' => 'off']), 'HTTPS=off');
    assertTrue(!request_is_https([]), 'plain HTTP');
    assertTrue(!request_is_https(['HTTP_X_FORWARDED_PROTO' => 'https']), 'forged header must be ignored');
    putenv('TRUST_PROXY=true');
    assertTrue(request_is_https(['HTTP_X_FORWARDED_PROTO' => 'https']), 'trusted proxy');
    putenv('TRUST_PROXY');
});

test('Session cookie is Secure on HTTPS only when SESSION_SECURE=auto', function (): void {
    assertTrue(session_cookie_secure(true, []), 'explicit true');
    assertTrue(!session_cookie_secure(false, ['HTTPS' => 'on']), 'explicit false');
    assertTrue(session_cookie_secure('auto', ['HTTPS' => 'on']), 'auto over HTTPS');
    assertTrue(!session_cookie_secure('auto', []), 'auto over HTTP (IP installation)');
});

test('Installation address is parsed for domains, IPv4 and IPv6', function (): void {
    $domain = app_address('https://panel.example.com');
    assertSame(['scheme' => 'https', 'host' => 'panel.example.com', 'is_ip' => false, 'https' => true], $domain);
    $ipv4 = app_address('http://203.0.113.10:8080');
    assertSame(['scheme' => 'http', 'host' => '203.0.113.10', 'is_ip' => true, 'https' => false], $ipv4);
    $ipv6 = app_address('http://[2001:db8::10]');
    assertSame('2001:db8::10', $ipv6['host']);
    assertTrue($ipv6['is_ip'], 'IPv6 is an IP');
});

test('Euro amounts typed by the admin are parsed into cents', function (): void {
    $cases = [
        '19,99' => 1999, '19.99' => 1999, '19,9' => 1990, '19' => 1900, '0,50' => 50, ',5' => 50,
        '€ 9,99' => 999, '1.234,56' => 123456, '1,234.56' => 123456, '  12,00 ' => 1200,
    ];
    foreach ($cases as $input => $cents) {
        assertSame($cents, \App\Core\Money::parseInput((string) $input), 'parse ' . $input);
    }
    foreach (['', 'abc', '12,345', '1.234', '-5', '1,2,3x', '12,5€x'] as $bad) {
        assertSame(null, \App\Core\Money::parseInput($bad), 'reject ' . $bad);
    }
    assertSame('19,99', \App\Core\Money::toInput(1999));
    assertSame('0,05', \App\Core\Money::toInput(5));
    assertSame(2490, \App\Core\Money::parseInput(\App\Core\Money::toInput(2490)), 'round trip');
});

test('Appearance settings are validated before reaching the page', function (): void {
    $theme = \App\Domain\Theme::normalize([
        'brand_color' => 'red; } body { display:none', 'accent_color' => '#ABC', 'theme_mode' => 'neon',
        'logo_data' => 'javascript:alert(1)', 'topbar_link' => 'javascript:alert(1)',
        'custom_css' => '</style><script>x</script>@import url(http://evil);.a{color:red}',
        'home_sections' => ['plans', 'bogus'],
    ]);
    assertSame(\App\Domain\Theme::defaults()['brand_color'], $theme['brand_color']);
    assertSame('#aabbcc', $theme['accent_color']);
    assertSame(\App\Domain\Theme::defaults()['theme_mode'], $theme['theme_mode']);
    assertSame('', $theme['logo_data']);
    assertSame('', $theme['topbar_link']);
    assertTrue(!str_contains(strtolower($theme['custom_css']), '<'), 'no tags in custom CSS');
    assertTrue(!str_contains($theme['custom_css'], '@import'), 'no @import');
    assertSame(['plans'], $theme['home_sections']);
    assertSame('#ffffff', \App\Domain\Theme::inkFor('#1e3a8a'));
    assertSame('#0a0f1a', \App\Domain\Theme::inkFor('#facc15'));
    assertSame('image/png', \App\Domain\Theme::sniffImage("\x89PNG\r\n\x1a\nrest"));
    assertSame(null, \App\Domain\Theme::sniffImage('<svg onload=alert(1)>'));
});

test('Sales and customisation need a valid licence', function (): void {
    foreach (['unlicensed' => false, 'invalid' => false, 'expired' => false, 'active' => true, 'lifetime' => true, 'grace' => true] as $status => $allowed) {
        \App\Domain\LicenseClient::fake($status);
        assertSame($allowed, \App\Domain\LicenseClient::allowsSales(), 'sales with ' . $status);
        assertSame($allowed, \App\Domain\LicenseClient::allowsCustomization(), 'customisation with ' . $status);
    }
    \App\Domain\LicenseClient::fake('active');
    App::setSetting('brand_color', '#ff0000');
    assertSame('#ff0000', \App\Domain\Theme::get()['brand_color'], 'licensed: saved colour applies');
    \App\Domain\LicenseClient::fake('unlicensed');
    assertSame(\App\Domain\Theme::defaults()['brand_color'], \App\Domain\Theme::get()['brand_color'], 'unlicensed: default look');
    assertSame('#ff0000', \App\Domain\Theme::stored()['brand_color'], 'unlicensed: saved value kept');
    \App\Domain\LicenseClient::fake(null);
});

test('Licence errors are explained and public keys validated', function (): void {
    assertTrue(\App\Domain\LicenseClient::validPublicKey(base64_encode(random_bytes(32))), 'valid key');
    assertTrue(!\App\Domain\LicenseClient::validPublicKey('abc'), 'short key rejected');
    assertTrue(\App\Domain\LicenseClient::explain('license_revoked') !== 'license_revoked', 'code translated');
    assertSame('something odd', \App\Domain\LicenseClient::explain('something odd'));
});

test('Fixed plans ignore requested resources and charge the plan price', function (): void {
    $db = new Database(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
    App::setDatabase($db);
    Schema::install($db);
    $categoryId = $db->insert('categories', ['name' => 'Minecraft', 'slug' => 'mc']);
    $id = $db->insert('products', ['category_id' => $categoryId, 'name' => 'Plus', 'slug' => 'plus', 'min_ram_mb' => 1024, 'max_ram_mb' => 16384, 'created_at' => Database::now(),
        'meta_json' => Json::encode(['plan_type' => 'fixed', 'fixed' => ['ram_mb' => 4096, 'cpu_percent' => 200, 'disk_mb' => 20480]])]);
    $db->insert('product_prices', ['product_id' => $id, 'cycle' => 'monthly', 'price_cents' => 899, 'setup_cents' => 0]);
    $db->insert('resource_options', ['product_id' => null, 'resource' => 'ram', 'min_value' => 1024, 'max_value' => 16384, 'step' => 1024, 'included' => 1024, 'unit_cents' => 300, 'unit' => 'GB']);

    $fixed = \App\Domain\Catalog::productById($id);
    assertSame('fixed', $fixed['plan_type']);
    $quote = PriceCalculator::quote($fixed, ['ram_mb' => 32768, 'cpu_percent' => 800, 'disk_mb' => 999999], [], 'monthly');
    assertSame(4096, $quote['config']['ram_mb'], 'RAM pinned');
    assertSame(200, $quote['config']['cpu_percent'], 'CPU pinned');
    assertSame(20480, $quote['config']['disk_mb'], 'disk pinned');
    assertSame(899, $quote['subtotal_cents'], 'no resource surcharge');

    $db->update('products', ['meta_json' => Json::encode(['plan_type' => 'custom'])], 'id = :id', ['id' => $id]);
    $custom = \App\Domain\Catalog::productById($id);
    assertSame('custom', $custom['plan_type']);
    $quote = PriceCalculator::quote($custom, ['ram_mb' => 3072], [], 'monthly');
    assertSame(899 + 600, $quote['subtotal_cents'], 'configurable plan charges extra RAM');
});

test('Every route points to an existing controller method', function (): void {
    $routes = (string) file_get_contents(dirname(__DIR__) . '/src/Http/routes.php');
    preg_match_all('/\[([A-Za-z]+)::class, \'([A-Za-z]+)\'\]/', $routes, $matches, PREG_SET_ORDER);
    assertTrue(count($matches) > 50, 'routes found');
    foreach ($matches as [, $class, $method]) {
        $fqcn = 'App\\Http\\' . $class;
        assertTrue(method_exists($fqcn, $method), 'missing ' . $class . '::' . $method);
    }
});

test('Configurator prices by resources, expansion packs add resources', function (): void {
    $db = new Database(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
    App::setDatabase($db);
    Schema::install($db);
    \App\Domain\Installer::packages();
    $custom = \App\Domain\Catalog::product('custom-server');
    assertTrue($custom !== null && $custom['plan_type'] === 'custom', 'configurator created');
    // 4 GB RAM (8,00) + 2 vCPU (3,00) + 10 GB SSD (1,00) = 12,00 € a month
    $monthly = PriceCalculator::quote($custom, ['ram_mb' => 4096, 'cpu_percent' => 200, 'disk_mb' => 10240], [], 'monthly');
    assertSame(1200, $monthly['subtotal_cents'], 'monthly configurator price');
    $annual = PriceCalculator::quote($custom, ['ram_mb' => 4096, 'cpu_percent' => 200, 'disk_mb' => 10240], [], 'annual');
    assertSame(14400, $annual['subtotal_cents'], 'annual = 12 months');

    $package = \App\Domain\Catalog::product('package-2gb');
    assertSame('fixed', $package['plan_type']);
    $ramPack = (int) $db->value("SELECT id FROM addons WHERE code = 'pack_ram_2gb'");
    $quote = PriceCalculator::quote($package, [], [$ramPack], 'monthly');
    assertSame(4096, $quote['config']['ram_mb'], 'package 2 GB + 2 GB pack');
    assertSame(100, $quote['config']['cpu_percent'], '1 vCPU');
    assertSame(400 + 300, $quote['subtotal_cents'], 'package + pack price');
    assertSame('2 vCPU', PriceCalculator::humanResource('cpu', 200));
    assertSame('1,5', PriceCalculator::cores(150));

    $again = \App\Domain\Installer::packages();
    assertSame(0, $again['products'], 'idempotent');
});

// ---------------------------------------------------------------------------

fwrite(STDOUT, sprintf("\n%d passed, %d failed\n", $passed, $failed));
if ($failed > 0) {
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . PHP_EOL);
    }
    exit(1);
}
exit(0);
