<?php
$pageTitle = (string) $product['name'];
include __DIR__ . '/partials/head.php';
$ramOption = $product['options']['ram'] ?? ['min_value' => $product['min_ram_mb'], 'max_value' => $product['max_ram_mb'], 'step' => 1024];
$cpuOption = $product['options']['cpu'] ?? ['min_value' => 100, 'max_value' => 400, 'step' => 50];
$diskOption = $product['options']['disk'] ?? ['min_value' => 5120, 'max_value' => 102400, 'step' => 5120];
?>
<section style="margin-bottom:26px">
    <p class="muted" style="font-size:.9rem"><a href="<?= e(url('/catalog')) ?>"><?= e(__('catalog')) ?></a> / <a href="<?= e(url('/catalog/' . $product['category_slug'])) ?>"><?= e((string) $product['category_name']) ?></a> / <?= e((string) $product['name']) ?></p>
    <span class="pill"><?= e(__('tier_' . $product['tier'])) ?></span>
    <h1 style="margin-top:12px"><?= e((string) $product['name']) ?></h1>
    <p class="lead"><?= e((string) $product['description']) ?></p>
</section>

<form method="post" action="<?= e(url('/cart/add')) ?>" class="configurator" data-configurator data-product-id="<?= (int) $product['id'] ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

    <div>
        <?php if (($product['plan_type'] ?? 'custom') === 'fixed'): ?>
        <div class="card">
            <h3 class="card-title"><?= icon('cpu') ?><?= e(__('plan_resources')) ?></h3>
            <input type="hidden" name="ram_mb" value="<?= (int) $ramOption['min_value'] ?>">
            <input type="hidden" name="cpu_percent" value="<?= (int) $cpuOption['min_value'] ?>">
            <input type="hidden" name="disk_mb" value="<?= (int) $diskOption['min_value'] ?>">
            <table class="kv">
                <tr><th><?= icon('layers', 'icon icon-sm') ?> <?= e(__('ram')) ?></th><td><strong><?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $ramOption['min_value'])) ?></strong></td></tr>
                <tr><th><?= icon('cpu', 'icon icon-sm') ?> <?= e(__('cpu')) ?></th><td><strong><?= e(\App\Domain\PriceCalculator::humanResource('cpu', (int) $cpuOption['min_value'])) ?></strong></td></tr>
                <tr><th><?= icon('disk', 'icon icon-sm') ?> <?= e(__('disk')) ?></th><td><strong><?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) $diskOption['min_value'])) ?></strong></td></tr>
            </table>
            <p class="muted" style="font-size:.86rem;margin:12px 0 0"><?= e(__('fixed_plan_note')) ?></p>
        </div>
        <?php else: ?>
        <div class="card">
            <h3 class="card-title"><?= icon('cpu') ?><?= e(__('resources')) ?></h3>

            <div class="field">
                <div class="slider-row">
                    <label for="ram_mb"><?= icon('layers') ?><?= e(__('ram')) ?></label>
                    <output for="ram_mb"><?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $selection['ram_mb'])) ?></output>
                </div>
                <input type="range" id="ram_mb" name="ram_mb"
                       min="<?= (int) $ramOption['min_value'] ?>" max="<?= (int) $ramOption['max_value'] ?>"
                       step="<?= max(1, (int) $ramOption['step']) ?>" value="<?= (int) $selection['ram_mb'] ?>"
                       data-divide="1024" data-suffix=" GB">
                <small class="muted"><?= e(__('ram_help')) ?></small>
            </div>

            <div class="field">
                <div class="slider-row">
                    <label for="cpu_percent"><?= icon('cpu') ?><?= e(__('cpu')) ?></label>
                    <output for="cpu_percent"><?= e(\App\Domain\PriceCalculator::humanResource('cpu', (int) $selection['cpu_percent'])) ?></output>
                </div>
                <input type="range" id="cpu_percent" name="cpu_percent"
                       min="<?= (int) $cpuOption['min_value'] ?>" max="<?= (int) $cpuOption['max_value'] ?>"
                       step="<?= max(1, (int) $cpuOption['step']) ?>" value="<?= (int) $selection['cpu_percent'] ?>"
                       data-divide="100" data-suffix=" vCPU">
            </div>

            <div class="field">
                <div class="slider-row">
                    <label for="disk_mb"><?= icon('disk') ?><?= e(__('disk')) ?></label>
                    <output for="disk_mb"><?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) $selection['disk_mb'])) ?></output>
                </div>
                <input type="range" id="disk_mb" name="disk_mb"
                       min="<?= (int) $diskOption['min_value'] ?>" max="<?= (int) $diskOption['max_value'] ?>"
                       step="<?= max(1, (int) $diskOption['step']) ?>" value="<?= (int) $selection['disk_mb'] ?>"
                       data-divide="1024" data-suffix=" GB">
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <h3 class="card-title"><?= icon('box') ?><?= e(__('software_and_version')) ?></h3>
            <div class="field-row">
                <div class="field">
                    <label for="software"><?= e(__('software')) ?></label>
                    <select id="software" name="software">
                        <?php foreach (['paper', 'purpur', 'vanilla', 'spigot', 'fabric', 'forge', 'neoforge', 'velocity', 'bungeecord', 'bedrock'] as $software): ?>
                            <option value="<?= e($software) ?>" <?= $software === $selection['software'] ? 'selected' : '' ?>><?= e(ucfirst($software)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="version"><?= e(__('version')) ?></label>
                    <select id="version" name="version">
                        <?php foreach ($product['versions'] as $version): ?>
                            <option value="<?= e($version) ?>" <?= $version === $selection['version'] ? 'selected' : '' ?>><?= e($version) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="location_id"><?= e(__('location')) ?></label>
                    <select id="location_id" name="location_id">
                        <option value=""><?= e(__('auto_best_node')) ?></option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= (int) $location['id'] ?>"><?= e((string) $location['name']) ?> · <?= e((string) $location['country']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="cycle"><?= e(__('billing_cycle')) ?></label>
                    <select id="cycle" name="cycle">
                        <?php foreach ($cycles as $cycle): ?>
                            <option value="<?= e($cycle) ?>"><?= e(__($cycle)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <?php if ($product['addons'] !== []): ?>
            <?php
            $resourcePacks = array_values(array_filter($product['addons'], static fn (array $a): bool => \App\Domain\PriceCalculator::addonEffect($a) !== null));
            $otherPacks = array_values(array_filter($product['addons'], static fn (array $a): bool => \App\Domain\PriceCalculator::addonEffect($a) === null));
            $selectedAddons = array_map('intval', (array) ($quote['addon_ids'] ?? []));
            ?>
            <div class="card">
                <h3 class="card-title"><?= icon('puzzle') ?><?= e(__('expansion_packs')) ?> <small class="muted" style="font-weight:400">(<?= e(__('optional')) ?>)</small></h3>
                <p class="card-hint"><?= e(($product['plan_type'] ?? 'custom') === 'fixed' ? __('expansion_packs_fixed_hint') : __('expansion_packs_hint')) ?></p>
                <?php foreach ([['resource_packs', $resourcePacks], ['service_packs', $otherPacks]] as [$groupLabel, $groupAddons]): ?>
                    <?php if ($groupAddons === []) { continue; } ?>
                    <p style="font-weight:600;font-size:.88rem;margin:12px 0 6px"><?= e(__($groupLabel)) ?></p>
                    <div class="addon-list">
                        <?php foreach ($groupAddons as $addon): ?>
                            <?php $effectLabel = \App\Domain\PriceCalculator::addonEffectLabel($addon); ?>
                            <label class="addon">
                                <input type="checkbox" name="addons[]" value="<?= (int) $addon['id'] ?>" <?= in_array((int) $addon['id'], $selectedAddons, true) ? 'checked' : '' ?>>
                                <span><?= e((string) $addon['name']) ?><?php if ($effectLabel !== '' && stripos((string) $addon['name'], $effectLabel) === false): ?> <small class="muted">(<?= e($effectLabel) ?>)</small><?php endif; ?><?php if (!empty($addon['description'])): ?><br><small class="muted"><?= e((string) $addon['description']) ?></small><?php endif; ?></span>
                                <strong><?= e(money((int) $addon['price_cents'])) ?> / <?= e(__('month_short')) ?></strong>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (($product['plan_type'] ?? 'custom') === 'fixed' && ($configurator ?? null) !== null): ?>
            <div class="card card-flat">
                <div class="row-between">
                    <div>
                        <strong><?= e(__('need_different_resources')) ?></strong>
                        <p class="muted" style="margin:2px 0 0;font-size:.9rem"><?= e(__('need_different_resources_body')) ?></p>
                    </div>
                    <a class="btn btn-ghost" href="<?= e(url('/server/' . $configurator['slug'])) ?>"><?= icon('settings') ?><?= e(__('open_configurator')) ?></a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <aside class="card summary" data-summary>
        <h3 class="card-title"><?= icon('cart') ?><?= e(__('your_configuration')) ?></h3>
        <div class="row"><span><?= e(__('base_price')) ?></span><span data-base><?= e(money((int) $quote['base_cents'])) ?></span></div>
        <div data-lines>
            <?php foreach (array_merge($quote['resources'], array_map(static fn (array $l): array => ['label' => $l['name'], 'amount_cents' => $l['amount_cents']], $quote['addons'] ?? [])) as $line): ?>
                <div class="row"><span><?= e((string) $line['label']) ?></span><span><?= e(money((int) $line['amount_cents'])) ?></span></div>
            <?php endforeach; ?>
        </div>
        <div class="total"><span><?= e(__('total')) ?></span><span data-total><?= e(money((int) $quote['total_cents'])) ?></span></div>
        <small class="muted"><?= e(__('vat_included')) ?></small>
        <?php if (\App\Domain\LicenseClient::allowsSales()): ?>
            <button class="btn btn-block btn-lg" type="submit" style="margin-top:18px"><?= icon('cart') ?><?= e(__('add_to_cart')) ?></button>
        <?php else: ?>
            <div class="alert alert-danger" style="margin:18px 0 0"><?= e(__('sales_closed_public')) ?></div>
        <?php endif; ?>
        <p class="muted" style="font-size:.8rem;margin-top:10px"><?= e(__('eula_notice')) ?></p>
    </aside>
</form>

<section>
    <h2 style="margin-bottom:18px"><?= e(__('what_is_included')) ?></h2>
    <div class="grid grid-4">
        <div class="card feature"><span class="feature-icon"><?= icon('server') ?></span><div><h3>Pterodactyl</h3><p><?= e(__('included_panel')) ?></p></div></div>
        <div class="card feature"><span class="feature-icon"><?= icon('cloud') ?></span><div><h3><?= e(__('backups')) ?></h3><p><?= e(__('included_backups_body')) ?></p></div></div>
        <div class="card feature"><span class="feature-icon"><?= icon('shield') ?></span><div><h3><?= e(__('ddos')) ?></h3><p><?= e(__('included_ddos_body')) ?></p></div></div>
        <div class="card feature"><span class="feature-icon"><?= icon('chat') ?></span><div><h3><?= e(__('support')) ?></h3><p><?= e(__('included_support_body')) ?></p></div></div>
    </div>
</section>

<?php include __DIR__ . '/partials/foot.php'; ?>
