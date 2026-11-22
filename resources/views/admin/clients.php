<?php $pageTitle = __('clients'); include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$badges = ['active' => 'badge-ok', 'blocked' => 'badge-bad', 'disabled' => 'badge-muted'];
?>
<div class="page-head">
    <div>
        <h1><?= e(__('clients')) ?></h1>
        <p><?= e(__('clients_lead')) ?></p>
    </div>
</div>

<form method="get" action="<?= e(url($adminBase . '/clients')) ?>" class="card" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px">
    <div class="field" style="flex:1;min-width:220px;margin:0">
        <label for="q"><?= e(__('search')) ?></label>
        <input id="q" name="q" value="<?= e((string) $term) ?>" placeholder="<?= e(__('search_client_placeholder')) ?>">
    </div>
    <div class="field" style="margin:0;min-width:170px">
        <label for="status"><?= e(__('status')) ?></label>
        <select id="status" name="status">
            <option value=""><?= e(__('all')) ?></option>
            <?php foreach (\App\Domain\UserRepository::STATUSES as $option): ?>
                <option value="<?= e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= e(__('account_status_' . $option)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn" type="submit"><?= e(__('search')) ?></button>
</form>

<div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th><?= e(__('client')) ?></th><th><?= e(__('status')) ?></th><th><?= e(__('services')) ?></th><th class="num"><?= e(__('wallet')) ?></th><th><?= e(__('last_login')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $userStatus = (string) ($user['status'] ?? 'active'); ?>
            <tr>
                <td>#<?= (int) $user['id'] ?></td>
                <td>
                    <a href="<?= e(url($adminBase . '/clients/' . (int) $user['id'])) ?>" style="color:var(--text);font-weight:600"><?= e((string) $user['username']) ?></a>
                    <?php if (($user['role'] ?? 'customer') !== 'customer'): ?><span class="pill" style="margin-left:4px"><?= e(__('role_' . $user['role'])) ?></span><?php endif; ?>
                    <br><small class="muted"><?= e($user['profile']['email']) ?></small>
                </td>
                <td><span class="badge <?= $badges[$userStatus] ?? 'badge-muted' ?>"><?= e(__('account_status_' . $userStatus)) ?></span></td>
                <td><?= (int) $user['services_count'] ?></td>
                <td class="num"><?= e(money((int) $user['wallet'])) ?></td>
                <td><small class="muted"><?= !empty($user['last_login_at']) ? e(fmt_date((string) $user['last_login_at'], 'd/m/Y H:i')) : '—' ?></small></td>
                <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(url($adminBase . '/clients/' . (int) $user['id'])) ?>"><?= e(__('manage')) ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($users === []): ?><tr><td colspan="7" class="muted"><?= e(__('no_results')) ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
