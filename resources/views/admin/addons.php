<?php $pageTitle = __('addons'); include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$effectSelect = static function (array $addon, string $form): string {
    $effect = \App\Domain\PriceCalculator::addonEffect($addon);
    $current = $effect['resource'] ?? '';
    $divisors = ['ram' => 1024, 'cpu' => 100, 'disk' => 1024];
    $amount = $effect !== null ? rtrim(rtrim(number_format($effect['amount'] / $divisors[$current], 2, ',', ''), '0'), ',') : '';
    $formAttr = $form !== '' ? ' form="' . e($form) . '"' : '';
    $html = '<div style="display:flex;gap:6px;align-items:center">';
    $html .= '<select name="effect_resource"' . $formAttr . ' style="width:auto" aria-label="' . e(__('pack_effect')) . '">';
    foreach (['' => __('pack_effect_none'), 'ram' => '+ RAM (GB)', 'cpu' => '+ vCPU', 'disk' => '+ SSD (GB)'] as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select><input name="effect_amount"' . $formAttr . ' inputmode="decimal" value="' . e($amount) . '" style="width:70px" aria-label="' . e(__('pack_amount')) . '" placeholder="0"></div>';

    return $html;
};
?>
<div class="page-head">
    <div>
        <h1><?= e(__('expansion_packs')) ?></h1>
        <p><?= e(__('addons_lead')) ?></p>
    </div>
</div>

<?php include __DIR__ . '/../partials/license_lock.php'; ?>
<fieldset class="plain" <?= $licenseLocked ? 'disabled' : '' ?>>
<div class="two-col">
    <div class="table-wrap">
        <table>
            <thead>
            <tr><th><?= e(__('name')) ?></th><th><?= e(__('pack_effect')) ?></th><th style="width:150px"><?= e(__('price_monthly')) ?></th><th><?= e(__('products')) ?></th><th><?= e(__('visible')) ?></th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($addons as $addon): ?>
                <?php $formId = 'addon-' . (int) $addon['id']; ?>
                <tr>
                    <td>
                        <input form="<?= e($formId) ?>" name="name" value="<?= e((string) $addon['name']) ?>" aria-label="<?= e(__('name')) ?>" required>
                        <small class="muted mono"><?= e((string) $addon['code']) ?></small>
                    </td>
                    <td><?= $effectSelect($addon, $formId) ?></td>
                    <td>
                        <div class="input-money"><input form="<?= e($formId) ?>" name="price" inputmode="decimal" data-money value="<?= e(money_input((int) $addon['price_cents'])) ?>" aria-label="<?= e(__('price')) ?>"></div>
                    </td>
                    <td><span class="badge badge-muted"><?= (int) $addon['products'] ?></span></td>
                    <td><label class="switch"><input form="<?= e($formId) ?>" type="checkbox" name="visible" value="1" <?= (int) $addon['visible'] === 1 ? 'checked' : '' ?> aria-label="<?= e(__('visible')) ?>"></label></td>
                    <td class="actions">
                        <form id="<?= e($formId) ?>" method="post" action="<?= e(url($adminBase . '/addons/' . (int) $addon['id'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="description" value="<?= e((string) ($addon['description'] ?? '')) ?>">
                            <button class="btn btn-sm" type="submit"><?= e(__('save')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($addons === []): ?>
                <tr><td colspan="6" class="muted"><?= e(__('no_addons')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <form method="post" action="<?= e(url($adminBase . '/addons')) ?>" class="card">
        <?= csrf_field() ?>
        <h3 class="card-title"><?= icon('plus') ?><?= e(__('new_addon')) ?></h3>
        <div class="field"><label for="new_name"><?= e(__('name')) ?></label><input id="new_name" name="name" required maxlength="120" placeholder="<?= e(__('addon_name_placeholder')) ?>"></div>
        <div class="field">
            <label for="new_price"><?= e(__('price_monthly')) ?></label>
            <div class="input-money"><input id="new_price" name="price" inputmode="decimal" data-money required placeholder="4,99"></div>
            <span class="field-help"><?= e(__('addon_price_help')) ?></span>
        </div>
        <div class="field"><label for="new_description"><?= e(__('description')) ?></label><input id="new_description" name="description" maxlength="255"></div>
        <div class="field">
            <label><?= e(__('pack_effect')) ?></label>
            <?= $effectSelect([], '') ?>
            <span class="field-help"><?= e(__('pack_effect_help')) ?></span>
        </div>
        <div class="stack" style="gap:10px;margin-bottom:16px">
            <label class="switch"><input type="checkbox" name="visible" value="1" checked><span><?= e(__('visible')) ?></span></label>
            <label class="switch"><input type="checkbox" name="attach_all" value="1" checked><span><?= e(__('attach_to_all_products')) ?></span></label>
        </div>
        <button class="btn btn-block" type="submit"><?= icon('plus') ?><?= e(__('create')) ?></button>
    </form>
</div>
</fieldset>
<?php include __DIR__ . '/../partials/foot.php'; ?>
