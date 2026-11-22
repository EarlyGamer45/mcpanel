<?php $pageTitle = __('products'); include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$cs = $catalogSettings;
?>
<div class="page-head">
    <div>
        <h1><?= e(__('products')) ?></h1>
        <p><?= e(__('products_lead_v2')) ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url($adminBase . '/addons')) ?>"><?= icon('puzzle') ?><?= e(__('expansion_packs')) ?></a>
</div>

<?php include __DIR__ . '/../partials/license_lock.php'; ?>
<fieldset class="plain" <?= $licenseLocked ? 'disabled' : '' ?>>

<div class="table-wrap">
    <table>
        <thead><tr><th><?= e(__('name')) ?></th><th><?= e(__('plan_type')) ?></th><th><?= e(__('category')) ?></th><th class="num"><?= e(__('price_from_month')) ?></th><th><?= e(__('resources')) ?></th><th><?= e(__('status')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <?php
            $productMeta = \App\Core\Json::decode($product['meta_json'] ?? null);
            $isFixed = ($product['plan_type'] ?? 'custom') === 'fixed';
            ?>
            <tr>
                <td>
                    <a href="<?= e(url($adminBase . '/products/' . (int) $product['id'])) ?>" style="color:var(--text);font-weight:600"><?= e((string) $product['name']) ?></a>
                    <?php if (!empty($productMeta['featured'])): ?><span class="pill" style="margin-left:4px;font-size:.65rem"><?= e(__('featured')) ?></span><?php endif; ?>
                    <br><small class="muted mono"><?= e((string) $product['slug']) ?></small>
                </td>
                <td><span class="badge <?= $isFixed ? 'badge-info' : 'badge-muted' ?>"><?= e(__('plan_type_' . ($isFixed ? 'fixed' : 'custom'))) ?></span></td>
                <td><?= e((string) $product['category_name']) ?></td>
                <td class="num"><strong><?= e(money((int) $product['from_cents'])) ?></strong></td>
                <td>
                    <?php if ($isFixed): ?>
                        <?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $product['fixed']['ram_mb'])) ?> · <?= e(\App\Domain\PriceCalculator::humanResource('cpu', (int) $product['fixed']['cpu_percent'])) ?> · <?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) $product['fixed']['disk_mb'])) ?> SSD
                    <?php else: ?>
                        RAM <?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $product['min_ram_mb'])) ?> – <?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $product['max_ram_mb'])) ?>
                    <?php endif; ?>
                </td>
                <td><?= (int) $product['visible'] === 1 ? '<span class="badge badge-ok">' . e(__('visible')) . '</span>' : '<span class="badge badge-muted">' . e(__('hidden')) . '</span>' ?></td>
                <td class="actions">
                    <a class="btn btn-ghost btn-sm" href="<?= e(url($adminBase . '/products/' . (int) $product['id'])) ?>"><?= e(__('edit')) ?></a>
                    <form method="post" action="<?= e(url($adminBase . '/products/' . (int) $product['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= e(__('duplicate')) ?></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($products === []): ?><tr><td colspan="7" class="muted"><?= e(__('no_products')) ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="grid grid-2" style="margin-top:18px">
    <form class="card" method="post" action="<?= e(url($adminBase . '/products/create')) ?>">
        <?= csrf_field() ?>
        <h3 class="card-title"><?= icon('plus') ?><?= e(__('new_product')) ?></h3>
        <div class="field"><label for="new_name"><?= e(__('name')) ?></label><input id="new_name" name="name" required maxlength="160" placeholder="<?= e(__('new_product_placeholder')) ?>"></div>
        <div class="field">
            <label for="new_category"><?= e(__('category')) ?></label>
            <select id="new_category" name="category_id" required>
                <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e((string) $category['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label><?= e(__('plan_type')) ?></label>
            <div class="choices">
                <label class="choice"><input type="radio" name="plan_type" value="fixed" checked><span><?= e(__('plan_type_fixed')) ?><small class="muted"><?= e(__('plan_type_fixed_hint')) ?></small></span></label>
                <label class="choice"><input type="radio" name="plan_type" value="custom"><span><?= e(__('plan_type_custom')) ?><small class="muted"><?= e(__('plan_type_custom_hint')) ?></small></span></label>
            </div>
        </div>
        <p class="muted" style="font-size:.85rem"><?= e(__('new_product_hidden_note')) ?></p>
        <button class="btn" type="submit"><?= e(__('create')) ?></button>
    </form>

    <form class="card" method="post" action="<?= e(url($adminBase . '/catalog-settings')) ?>">
        <?= csrf_field() ?>
        <h3 class="card-title"><?= icon('list') ?><?= e(__('catalog_display')) ?></h3>
        <div class="field" style="display:flex;gap:18px;flex-wrap:wrap">
            <label class="checkbox"><input type="checkbox" name="catalog_show_fixed" value="1" <?= $cs['show_fixed'] ? 'checked' : '' ?>><span><?= e(__('show_fixed_plans')) ?></span></label>
            <label class="checkbox"><input type="checkbox" name="catalog_show_custom" value="1" <?= $cs['show_custom'] ? 'checked' : '' ?>><span><?= e(__('show_custom_plans')) ?></span></label>
            <label class="checkbox"><input type="checkbox" name="catalog_show_packs" value="1" <?= $cs['show_packs'] ? 'checked' : '' ?>><span><?= e(__('show_expansion_packs')) ?></span></label>
        </div>
        <div class="field">
            <label><?= e(__('catalog_order')) ?></label>
            <div class="choices">
                <label class="choice"><input type="radio" name="catalog_order" value="fixed_first" <?= $cs['order'] === 'fixed_first' ? 'checked' : '' ?>><span><?= e(__('fixed_first')) ?></span></label>
                <label class="choice"><input type="radio" name="catalog_order" value="custom_first" <?= $cs['order'] === 'custom_first' ? 'checked' : '' ?>><span><?= e(__('custom_first')) ?></span></label>
            </div>
        </div>
        <div class="field"><label for="catalog_fixed_title"><?= e(__('fixed_section_title')) ?></label><input id="catalog_fixed_title" name="catalog_fixed_title" value="<?= e((string) setting('catalog_fixed_title', '')) ?>" placeholder="<?= e(__('catalog_fixed_title_default')) ?>"></div>
        <div class="field"><label for="catalog_fixed_lead"><?= e(__('fixed_section_lead')) ?></label><input id="catalog_fixed_lead" name="catalog_fixed_lead" value="<?= e((string) setting('catalog_fixed_lead', '')) ?>" placeholder="<?= e(__('catalog_fixed_lead_default')) ?>"></div>
        <div class="field"><label for="catalog_custom_title"><?= e(__('custom_section_title')) ?></label><input id="catalog_custom_title" name="catalog_custom_title" value="<?= e((string) setting('catalog_custom_title', '')) ?>" placeholder="<?= e(__('catalog_custom_title_default')) ?>"></div>
        <div class="field"><label for="catalog_custom_lead"><?= e(__('custom_section_lead')) ?></label><input id="catalog_custom_lead" name="catalog_custom_lead" value="<?= e((string) setting('catalog_custom_lead', '')) ?>" placeholder="<?= e(__('catalog_custom_lead_default')) ?>"></div>
        <div class="field"><label for="catalog_packs_title"><?= e(__('packs_section_title')) ?></label><input id="catalog_packs_title" name="catalog_packs_title" value="<?= e((string) setting('catalog_packs_title', '')) ?>" placeholder="<?= e(__('catalog_packs_title_default')) ?>"></div>
        <div class="field"><label for="catalog_packs_lead"><?= e(__('packs_section_lead')) ?></label><input id="catalog_packs_lead" name="catalog_packs_lead" value="<?= e((string) setting('catalog_packs_lead', '')) ?>" placeholder="<?= e(__('catalog_packs_lead_default')) ?>"></div>
        <button class="btn" type="submit"><?= e(__('save')) ?></button>
    </form>
</div>
</fieldset>
<?php include __DIR__ . '/../partials/foot.php'; ?>
