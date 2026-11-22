<?php $pageTitle = (string) $product['name']; include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$monthly = (int) ($product['prices']['monthly']['price_cents'] ?? 0);
$planType = $product['plan_type'] ?? 'custom';
$fixedSpecs = \App\Domain\Catalog::fixedSpecs($product);
$resourceMeta = [
    'ram' => ['icon' => 'layers', 'scale' => 1, 'unit' => 'MB'],
    'cpu' => ['icon' => 'cpu', 'scale' => 100, 'unit' => 'vCPU'],
    'disk' => ['icon' => 'disk', 'scale' => 1024, 'unit' => 'GB'],
];
$fallbackOptions = [
    'ram' => ['min_value' => (int) $product['min_ram_mb'], 'max_value' => (int) $product['max_ram_mb'], 'step' => 1024, 'included' => (int) $product['min_ram_mb'], 'unit_cents' => 0],
    'cpu' => ['min_value' => 100, 'max_value' => 400, 'step' => 50, 'included' => 100, 'unit_cents' => 0],
    'disk' => ['min_value' => 5120, 'max_value' => 102400, 'step' => 5120, 'included' => 10240, 'unit_cents' => 0],
];
?>
<div class="page-head">
    <div>
        <p class="muted" style="font-size:.88rem;margin-bottom:6px"><a href="<?= e(url($adminBase . '/products')) ?>"><?= e(__('products')) ?></a> / <?= e((string) $product['name']) ?></p>
        <h1><?= e(__('edit_product')) ?></h1>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/server/' . $product['slug'])) ?>" target="_blank" rel="noopener"><?= icon('external') ?><?= e(__('view_on_site')) ?></a>
</div>

<?php include __DIR__ . '/../partials/license_lock.php'; ?>
<fieldset class="plain" <?= $licenseLocked ? 'disabled' : '' ?>>
<form method="post" action="<?= e(url($adminBase . '/products/' . (int) $product['id'])) ?>" class="two-col">
    <?= csrf_field() ?>
    <div class="stack">
        <div class="card">
            <h3 class="card-title"><?= icon('box') ?><?= e(__('product_details')) ?></h3>
            <div class="field-row">
                <div class="field"><label for="name"><?= e(__('name')) ?></label><input id="name" name="name" value="<?= e((string) $product['name']) ?>" required></div>
                <div class="field"><label for="slug"><?= e(__('slug')) ?></label><input id="slug" name="slug" value="<?= e((string) $product['slug']) ?>" required class="mono"></div>
            </div>
            <div class="field"><label for="description"><?= e(__('description')) ?></label><textarea id="description" name="description" style="min-height:90px"><?= e((string) $product['description']) ?></textarea></div>
            <div class="field-row">
                <div class="field">
                    <label for="category_id"><?= e(__('category')) ?></label>
                    <select id="category_id" name="category_id">
                        <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $category['id'] === (int) $product['category_id'] ? 'selected' : '' ?>><?= e((string) $category['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="sort"><?= e(__('sort_order')) ?></label><input id="sort" name="sort" type="number" value="<?= (int) ($product['sort'] ?? 0) ?>"><span class="field-help"><?= e(__('sort_order_help')) ?></span></div>
            </div>
            <div class="field">
                <label><?= e(__('plan_type')) ?></label>
                <div class="choices" data-plan-switch>
                    <label class="choice"><input type="radio" name="plan_type" value="fixed" <?= $planType === 'fixed' ? 'checked' : '' ?>><span><?= e(__('plan_type_fixed')) ?><small class="muted"><?= e(__('plan_type_fixed_hint')) ?></small></span></label>
                    <label class="choice"><input type="radio" name="plan_type" value="custom" <?= $planType !== 'fixed' ? 'checked' : '' ?>><span><?= e(__('plan_type_custom')) ?><small class="muted"><?= e(__('plan_type_custom_hint')) ?></small></span></label>
                </div>
            </div>
            <div class="field">
                <label><?= e(__('tier')) ?></label>
                <div class="choices">
                    <?php foreach (['budget', 'standard', 'premium'] as $tier): ?>
                        <label class="choice"><input type="radio" name="tier" value="<?= e($tier) ?>" <?= $tier === $product['tier'] ? 'checked' : '' ?>><span><?= e(__('tier_' . $tier)) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card" data-plan="fixed">
            <h3 class="card-title"><?= icon('cpu') ?><?= e(__('fixed_plan_resources')) ?></h3>
            <p class="card-hint"><?= e(__('fixed_plan_resources_hint')) ?></p>
            <div class="field-row">
                <div class="field"><label for="fixed_ram_mb"><?= e(__('ram')) ?></label><div class="input-suffix"><input id="fixed_ram_mb" name="fixed_ram_mb" type="number" min="128" step="128" value="<?= (int) $fixedSpecs['ram_mb'] ?>"><span>MB</span></div></div>
                <div class="field"><label for="fixed_cpu_cores"><?= e(__('cpu')) ?></label><div class="input-suffix"><input id="fixed_cpu_cores" name="fixed_cpu_cores" inputmode="decimal" value="<?= e(\App\Domain\PriceCalculator::cores((int) $fixedSpecs['cpu_percent'])) ?>"><span>vCPU</span></div><span class="field-help"><?= e(__('cpu_cores_help')) ?></span></div>
                <div class="field"><label for="fixed_disk_gb"><?= e(__('disk')) ?></label><div class="input-suffix"><input id="fixed_disk_gb" name="fixed_disk_gb" type="number" min="1" value="<?= (int) intdiv((int) $fixedSpecs['disk_mb'], 1024) ?>"><span>GB</span></div></div>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title"><?= icon('euro') ?><?= e(__('prices')) ?></h3>
            <p class="card-hint"><?= e(__('prices_euro_hint')) ?></p>
            <div class="field-row">
                <?php foreach (\App\Core\Money::cycles() as $cycle): ?>
                    <?php
                    $cents = (int) ($product['prices'][$cycle]['price_cents'] ?? 0);
                    $months = \App\Domain\PriceCalculator::months($cycle);
                    ?>
                    <div class="field">
                        <label for="price_<?= e($cycle) ?>"><?= e(mb_convert_case(__($cycle), MB_CASE_TITLE)) ?></label>
                        <div class="input-money">
                            <input id="price_<?= e($cycle) ?>" name="price_<?= e($cycle) ?>" inputmode="decimal" data-money
                                   value="<?= $cents > 0 || $cycle === 'monthly' ? e(money_input($cents)) : '' ?>"
                                   placeholder="<?= e(money_input($monthly * $months)) ?>" <?= $cycle === 'monthly' ? 'required' : '' ?>>
                        </div>
                        <span class="field-help">
                            <?php if ($cycle === 'monthly'): ?>
                                <?= e(__('price_per_month')) ?>
                            <?php elseif ($cents > 0 && $monthly > 0): ?>
                                <?= e(__('price_equivalent', ['amount' => money(\App\Core\Money::divRound($cents, $months)), 'saving' => (string) max(0, 100 - (int) round($cents * 100 / max(1, $monthly * $months)))])) ?>
                            <?php else: ?>
                                <?= e(__('price_auto_from_monthly')) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card" data-plan="custom">
            <h3 class="card-title"><?= icon('cpu') ?><?= e(__('resource_pricing')) ?></h3>
            <p class="card-hint"><?= e(__('resource_pricing_hint')) ?></p>
            <div class="stack" style="gap:12px">
                <?php foreach ($resourceMeta as $resource => $info): ?>
                    <?php
                    $option = $product['configured_options'][$resource] ?? $fallbackOptions[$resource];
                    $scale = $info['scale'];
                    $isOwn = !empty($ownOptions[$resource]);
                    ?>
                    <div class="card card-flat" style="padding:16px">
                        <div class="row-between" style="margin-bottom:12px">
                            <strong style="display:inline-flex;gap:8px;align-items:center"><?= icon($info['icon'], 'icon icon-sm') ?><?= e(__($resource)) ?></strong>
                            <span class="badge <?= $isOwn ? 'badge-info' : 'badge-muted' ?>"><?= e($isOwn ? __('custom_for_product') : __('global_default')) ?></span>
                        </div>
                        <div class="resource-grid">
                            <?php foreach (['min_value' => ['min', 'minimum'], 'max_value' => ['max', 'maximum'], 'step' => ['step', 'step'], 'included' => ['included', 'included']] as $column => [$field, $labelKey]): ?>
                                <div>
                                    <label for="res_<?= e($resource . '_' . $field) ?>"><?= e(__($labelKey)) ?></label>
                                    <div class="input-suffix"><input id="res_<?= e($resource . '_' . $field) ?>" name="res_<?= e($resource) ?>_<?= e($field) ?>" type="number" min="0" value="<?= (int) intdiv((int) $option[$column], $scale) ?>"><span><?= e($info['unit']) ?></span></div>
                                </div>
                            <?php endforeach; ?>
                            <div>
                                <label for="res_<?= e($resource) ?>_unit"><?= e(__('price_per_step')) ?></label>
                                <div class="input-money"><input id="res_<?= e($resource) ?>_unit" name="res_<?= e($resource) ?>_unit" inputmode="decimal" data-money value="<?= e(money_input((int) $option['unit_cents'])) ?>"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title"><?= icon('server') ?><?= e(__('pterodactyl_mapping')) ?></h3>
            <div class="field-row">
                <div class="field"><label for="nest_id">Nest ID</label><input id="nest_id" name="nest_id" type="number" value="<?= (int) ($product['nest_id'] ?? 0) ?>"></div>
                <div class="field"><label for="egg_id">Egg ID</label><input id="egg_id" name="egg_id" type="number" value="<?= (int) ($product['egg_id'] ?? 0) ?>"></div>
            </div>
            <div class="field"><label for="docker_image"><?= e(__('docker_image')) ?></label><input id="docker_image" name="docker_image" class="mono" value="<?= e((string) ($product['docker_image'] ?? '')) ?>" placeholder="ghcr.io/pterodactyl/yolks:java_21"></div>
            <div class="field" style="margin-bottom:0"><label for="startup">Startup command</label><input id="startup" name="startup" class="mono" value="<?= e((string) ($product['startup'] ?? '')) ?>"></div>
        </div>
    </div>

    <aside class="stack">
        <div class="card">
            <h3 class="card-title"><?= icon('tag') ?><?= e(__('visibility')) ?></h3>
            <div class="stack" style="gap:12px">
                <label class="switch"><input type="checkbox" name="visible" value="1" <?= (int) $product['visible'] === 1 ? 'checked' : '' ?>><span><?= e(__('visible_in_catalog')) ?></span></label>
                <label class="switch"><input type="checkbox" name="featured" value="1" <?= !empty($meta['featured']) ? 'checked' : '' ?>><span><?= e(__('featured_product')) ?></span></label>
                <div class="field" style="margin:0">
                    <label for="badge"><?= e(__('product_badge')) ?></label>
                    <input id="badge" name="badge" maxlength="40" value="<?= e((string) ($meta['badge'] ?? '')) ?>" placeholder="<?= e(__('most_popular')) ?>">
                    <span class="field-help"><?= e(__('product_badge_help')) ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title"><?= icon('puzzle') ?><?= e(__('addons')) ?></h3>
            <?php if ($allAddons === []): ?>
                <p class="muted"><?= e(__('no_addons')) ?></p>
            <?php endif; ?>
            <div class="addon-list">
                <?php foreach ($allAddons as $addon): ?>
                    <label class="addon">
                        <input type="checkbox" name="addons[]" value="<?= (int) $addon['id'] ?>" <?= !empty($linkedAddons[(int) $addon['id']]) ? 'checked' : '' ?>>
                        <span><?= e((string) $addon['name']) ?><?php if ((int) $addon['visible'] !== 1): ?> <small class="muted">(<?= e(__('hidden')) ?>)</small><?php endif; ?></span>
                        <strong><?= e(money((int) $addon['price_cents'])) ?></strong>
                    </label>
                <?php endforeach; ?>
            </div>
            <p style="margin:12px 0 0;font-size:.88rem"><a href="<?= e(url($adminBase . '/addons')) ?>"><?= e(__('manage_addons')) ?> →</a></p>
        </div>

        <button class="btn btn-lg btn-block" type="submit"><?= icon('check') ?><?= e(__('save')) ?></button>
        <button class="btn btn-danger btn-block" type="submit" form="product-delete" onclick="return confirm('<?= e(__('product_delete_confirm')) ?>')"><?= e(__('delete_product')) ?></button>
        <p class="muted" style="font-size:.82rem;margin:0"><?= e(__('product_delete_help')) ?></p>
    </aside>
</form>
<form id="product-delete" method="post" action="<?= e(url($adminBase . '/products/' . (int) $product['id'] . '/delete')) ?>"><?= csrf_field() ?></form>
</fieldset>
<?php include __DIR__ . '/../partials/foot.php'; ?>
