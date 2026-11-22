<?php $pageTitle = __('nodes_and_locations'); include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$nodeForm = static function (array $node, array $locations, string $action) {
    $field = static fn (string $name, string $label, string $value, string $type = 'number', string $extra = ''): string =>
        '<div class="field"><label>' . e($label) . '</label><input name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" ' . $extra . '></div>';
    ob_start(); ?>
    <form method="post" action="<?= e(url($action)) ?>">
        <?= csrf_field() ?>
        <div class="field-row">
            <?= $field('name', __('name'), (string) ($node['name'] ?? ''), 'text', 'required maxlength="120"') ?>
            <div class="field">
                <label><?= e(__('location')) ?></label>
                <select name="location_id" required>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>" <?= (int) ($node['location_id'] ?? 0) === (int) $location['id'] ? 'selected' : '' ?>><?= e((string) $location['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?= $field('pterodactyl_node_id', __('pterodactyl_node_id'), (string) ($node['pterodactyl_node_id'] ?? ''), 'number', 'min="1" required') ?>
        </div>
        <div class="field-row">
            <?= $field('memory_mb', __('node_memory_mb'), (string) ($node['memory_mb'] ?? ''), 'number', 'min="0" required') ?>
            <?= $field('disk_mb', __('node_disk_mb'), (string) ($node['disk_mb'] ?? ''), 'number', 'min="0"') ?>
            <?= $field('cpu_percent', __('node_cpu_percent'), (string) ($node['cpu_percent'] ?? '0'), 'number', 'min="0"') ?>
            <?= $field('overallocation_pct', __('node_overallocation'), (string) ($node['overallocation_pct'] ?? '100'), 'number', 'min="100" max="1000"') ?>
            <div class="field">
                <label><?= e(__('monthly_cost')) ?></label>
                <div class="input-money"><input name="monthly_cost" inputmode="decimal" data-money value="<?= e(money_input((int) ($node['monthly_cost_cents'] ?? 0))) ?>"></div>
            </div>
        </div>
        <div class="form-actions" style="justify-content:space-between">
            <div style="display:flex;gap:18px;flex-wrap:wrap">
                <label class="checkbox"><input type="checkbox" name="visible" value="1" <?= (int) ($node['visible'] ?? 1) === 1 ? 'checked' : '' ?>><span><?= e(__('node_accepts_new')) ?></span></label>
                <label class="checkbox"><input type="checkbox" name="maintenance" value="1" <?= (int) ($node['maintenance'] ?? 0) === 1 ? 'checked' : '' ?>><span><?= e(__('maintenance')) ?></span></label>
            </div>
            <button class="btn" type="submit"><?= e(__('save')) ?></button>
        </div>
    </form>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-head">
    <div>
        <h1><?= e(__('nodes_and_locations')) ?></h1>
        <p><?= e(__('nodes_lead')) ?></p>
    </div>
    <form method="post" action="<?= e(url($adminBase . '/nodes/sync')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-ghost" type="submit" <?= $pterodactylReady ? '' : 'disabled title="' . e(__('pterodactyl_not_configured')) . '"' ?>><?= icon('cloud') ?><?= e(__('import_from_pterodactyl')) ?></button>
    </form>
</div>

<h2><?= e(__('nodes')) ?></h2>
<?php if ($nodes === []): ?>
    <div class="alert alert-info"><?= e(__('no_nodes_yet')) ?></div>
<?php endif; ?>
<div class="stack">
    <?php foreach ($nodes as $node): ?>
        <?php
        $ramPct = $node['ram_capacity'] > 0 ? min(100, (int) round($node['ram_used'] * 100 / $node['ram_capacity'])) : 0;
        $diskPct = (int) $node['disk_mb'] > 0 ? min(100, (int) round($node['disk_used'] * 100 / (int) $node['disk_mb'])) : 0;
        ?>
        <div class="card" id="node-<?= (int) $node['id'] ?>">
            <div class="row-between">
                <div>
                    <strong style="font-size:1.05rem"><?= e((string) $node['name']) ?></strong>
                    <span class="muted"> · <?= e((string) ($node['location_name'] ?? '—')) ?> · Pterodactyl #<?= (int) $node['pterodactyl_node_id'] ?></span>
                    <?php if ((int) $node['maintenance'] === 1): ?><span class="badge badge-warn"><?= e(__('maintenance')) ?></span><?php endif; ?>
                    <?php if ((int) $node['visible'] !== 1): ?><span class="badge badge-muted"><?= e(__('node_closed')) ?></span><?php endif; ?>
                </div>
                <span class="muted" style="font-size:.88rem"><?= e(__('node_services_count', ['count' => (string) $node['services']])) ?> · <?= e(money((int) $node['monthly_cost_cents'])) ?>/<?= e(__('month_short')) ?></span>
            </div>
            <div class="grid grid-2" style="margin-top:10px;gap:14px">
                <div>
                    <small class="muted">RAM <?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $node['ram_used'])) ?> / <?= e(\App\Domain\PriceCalculator::humanResource('ram', (int) $node['ram_capacity'])) ?> (<?= $ramPct ?>%)</small>
                    <div class="progress <?= $ramPct >= 90 ? 'bad' : ($ramPct >= 75 ? 'warn' : '') ?>"><span style="width:<?= $ramPct ?>%"></span></div>
                </div>
                <div>
                    <small class="muted"><?= e(__('disk')) ?> <?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) $node['disk_used'])) ?> / <?= e(\App\Domain\PriceCalculator::humanResource('disk', (int) $node['disk_mb'])) ?> (<?= $diskPct ?>%)</small>
                    <div class="progress <?= $diskPct >= 90 ? 'bad' : ($diskPct >= 75 ? 'warn' : '') ?>"><span style="width:<?= $diskPct ?>%"></span></div>
                </div>
            </div>
            <details style="margin-top:14px">
                <summary><?= e(__('edit')) ?></summary>
                <div style="margin-top:12px">
                    <?= $nodeForm($node, $locations, $adminBase . '/nodes/' . (int) $node['id']) ?>
                    <?php if ((int) $node['total_services'] === 0): ?>
                        <form method="post" action="<?= e(url($adminBase . '/nodes/' . (int) $node['id'] . '/delete')) ?>" style="margin-top:10px">
                            <?= csrf_field() ?>
                            <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('<?= e(__('node_delete_confirm')) ?>')"><?= e(__('delete_node')) ?></button>
                        </form>
                    <?php else: ?>
                        <p class="muted" style="font-size:.85rem;margin:10px 0 0"><?= e(__('node_delete_blocked')) ?></p>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <h3 class="card-title"><?= icon('plus') ?><?= e(__('add_node')) ?></h3>
        <?php if ($locations === []): ?>
            <p class="muted" style="margin:0"><?= e(__('add_location_first')) ?></p>
        <?php else: ?>
            <?= $nodeForm(['visible' => 1, 'overallocation_pct' => 100], $locations, $adminBase . '/nodes') ?>
        <?php endif; ?>
    </div>
</div>

<h2 id="locations" style="margin-top:36px"><?= e(__('locations')) ?></h2>
<div class="table-wrap">
    <table>
        <thead><tr><th><?= e(__('name')) ?></th><th>Slug</th><th><?= e(__('country')) ?></th><th>Pterodactyl ID</th><th><?= e(__('ddos_note')) ?></th><th><?= e(__('visible')) ?></th><th><?= e(__('maintenance')) ?></th><th><?= e(__('nodes')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($locations as $location): ?>
            <?php $formId = 'loc-' . (int) $location['id']; ?>
            <tr>
                <td><input form="<?= e($formId) ?>" name="name" value="<?= e((string) $location['name']) ?>" required style="min-width:120px"></td>
                <td><input form="<?= e($formId) ?>" name="slug" value="<?= e((string) $location['slug']) ?>" class="mono" style="min-width:100px"></td>
                <td><input form="<?= e($formId) ?>" name="country" value="<?= e((string) $location['country']) ?>" maxlength="2" style="width:60px"></td>
                <td><input form="<?= e($formId) ?>" name="pterodactyl_location_id" type="number" min="0" value="<?= (int) ($location['pterodactyl_location_id'] ?? 0) ?>" style="width:90px"></td>
                <td><input form="<?= e($formId) ?>" name="ddos_note" value="<?= e((string) ($location['ddos_note'] ?? '')) ?>" style="min-width:140px"></td>
                <td><input form="<?= e($formId) ?>" type="checkbox" name="visible" value="1" <?= (int) $location['visible'] === 1 ? 'checked' : '' ?> aria-label="<?= e(__('visible')) ?>"></td>
                <td><input form="<?= e($formId) ?>" type="checkbox" name="maintenance" value="1" <?= (int) $location['maintenance'] === 1 ? 'checked' : '' ?> aria-label="<?= e(__('maintenance')) ?>"></td>
                <td><?= (int) $location['nodes'] ?></td>
                <td class="actions">
                    <form id="<?= e($formId) ?>" method="post" action="<?= e(url($adminBase . '/locations/' . (int) $location['id'])) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= e(__('save')) ?></button></form>
                    <?php if ((int) $location['nodes'] === 0): ?>
                        <form method="post" action="<?= e(url($adminBase . '/locations/' . (int) $location['id'] . '/delete')) ?>"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('<?= e(__('location_delete_confirm')) ?>')"><?= e(__('delete')) ?></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td><input form="loc-new" name="name" placeholder="<?= e(__('new_location_placeholder')) ?>" required></td>
            <td><input form="loc-new" name="slug" placeholder="auto" class="mono"></td>
            <td><input form="loc-new" name="country" value="IT" maxlength="2" style="width:60px"></td>
            <td><input form="loc-new" name="pterodactyl_location_id" type="number" min="0" style="width:90px"></td>
            <td><input form="loc-new" name="ddos_note"></td>
            <td><input form="loc-new" type="checkbox" name="visible" value="1" checked aria-label="<?= e(__('visible')) ?>"></td>
            <td></td><td></td>
            <td class="actions"><form id="loc-new" method="post" action="<?= e(url($adminBase . '/locations')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= icon('plus', 'icon icon-sm') ?><?= e(__('add')) ?></button></form></td>
        </tr>
        </tbody>
    </table>
</div>
<p class="muted" style="font-size:.86rem;margin-top:12px"><?= e(__('nodes_footer_help')) ?></p>
<?php include __DIR__ . '/../partials/foot.php'; ?>
