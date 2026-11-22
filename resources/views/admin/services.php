<?php $pageTitle = __('services'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<?php $adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/'); ?>
<section>
    <h1><?= e(__('services')) ?></h1>
    <div class="admin-nav">
        <?php foreach (['' => __('all'), 'active' => __('active'), 'pending' => __('pending'), 'installing' => __('installing'), 'suspended' => __('suspended'), 'terminated' => __('terminated')] as $value => $label): ?>
            <a href="<?= e(url($adminBase . '/services' . ($value !== '' ? '?status=' . $value : ''))) ?>" class="<?= $status === $value ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th><?= e(__('service')) ?></th><th><?= e(__('client')) ?></th><th><?= e(__('resources')) ?></th><th class="num"><?= e(__('price')) ?></th><th><?= e(__('status')) ?></th><th><?= e(__('provisioning')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($services as $service): ?>
                <tr>
                    <td>#<?= (int) $service['id'] ?></td>
                    <td><strong><?= e((string) $service['name']) ?></strong><br><small class="muted"><?= e((string) $service['product_name']) ?> · <?= e((string) ($service['ip'] ? $service['ip'] . ':' . $service['port'] : '—')) ?></small></td>
                    <td><?= e((string) $service['username']) ?></td>
                    <td class="muted" style="font-size:.82rem"><?= (int) round((int) $service['ram_mb'] / 1024) ?> GB · <?= (int) $service['cpu_percent'] ?>% · <?= (int) round((int) $service['disk_mb'] / 1024) ?> GB</td>
                    <td class="num"><?= e(money((int) $service['price_cents'])) ?></td>
                    <td><?= status_badge((string) $service['status']) ?></td>
                    <td><small class="muted"><?= e((string) $service['provisioning_state']) ?></small></td>
                    <td class="actions">
                        <?php foreach (['suspend' => __('suspend'), 'unsuspend' => __('reactivate'), 'terminate' => __('terminate'), 'provision' => __('retry_provisioning'), 'apply' => __('apply_resources'), 'extend' => __('extend')] as $action => $label): ?>
                            <form method="post" action="<?= e(url($adminBase . '/services/' . (int) $service['id'] . '/action')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= e($action) ?>">
                                <button class="btn <?= $action === 'terminate' ? 'btn-danger' : 'btn-ghost' ?> btn-sm" type="submit"><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($services === []): ?><tr><td colspan="8" class="muted"><?= e(__('no_services')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
