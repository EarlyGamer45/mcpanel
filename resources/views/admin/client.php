<?php $pageTitle = (string) $client['username']; include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$base = $adminBase . '/clients/' . (int) $client['id'];
$status = (string) $client['status'];
$statusBadge = ['active' => 'badge-ok', 'blocked' => 'badge-bad', 'disabled' => 'badge-muted'][$status] ?? 'badge-muted';
$locked = !empty($client['locked_until']) && strtotime((string) $client['locked_until'] . ' UTC') > time();
?>
<div class="page-head">
    <div>
        <p class="muted" style="font-size:.88rem;margin-bottom:4px"><a href="<?= e(url($adminBase . '/clients')) ?>"><?= e(__('clients')) ?></a> / #<?= (int) $client['id'] ?></p>
        <h1><?= e((string) $client['username']) ?>
            <span class="badge <?= $statusBadge ?>" style="vertical-align:middle"><?= e(__('account_status_' . $status)) ?></span>
            <?php if ($locked): ?><span class="badge badge-warn" style="vertical-align:middle"><?= e(__('temporarily_locked')) ?></span><?php endif; ?>
        </h1>
        <p><?= e($profile['email']) ?> · <?= e(__('role_' . $client['role'])) ?></p>
    </div>
</div>

<?php if (!$canManage): ?>
    <div class="alert"><?= e(__('staff_account_readonly')) ?></div>
<?php endif; ?>

<div class="two-col">
    <div class="stack">
        <form class="card" method="post" action="<?= e(url($base . '/update')) ?>">
            <?= csrf_field() ?>
            <fieldset class="plain" <?= $canManage ? '' : 'disabled' ?>>
            <h3 class="card-title"><?= icon('user') ?><?= e(__('account_details')) ?></h3>
            <div class="field-row">
                <div class="field"><label for="username"><?= e(__('username')) ?></label><input id="username" name="username" value="<?= e((string) $client['username']) ?>" required></div>
                <div class="field"><label for="email"><?= e(__('email')) ?></label><input id="email" name="email" type="email" value="<?= e($profile['email']) ?>" required></div>
            </div>
            <div class="field-row">
                <div class="field"><label for="company"><?= e(__('company')) ?></label><input id="company" name="company" value="<?= e($profile['company']) ?>"></div>
                <div class="field"><label for="phone"><?= e(__('phone')) ?></label><input id="phone" name="phone" value="<?= e($profile['phone']) ?>"></div>
            </div>
            <div class="field"><label for="address"><?= e(__('address')) ?></label><input id="address" name="address" value="<?= e($profile['address']) ?>"></div>
            <div class="field-row">
                <div class="field"><label for="city"><?= e(__('city')) ?></label><input id="city" name="city" value="<?= e($profile['city']) ?>"></div>
                <div class="field"><label for="postal_code"><?= e(__('postal_code')) ?></label><input id="postal_code" name="postal_code" value="<?= e($profile['postal_code']) ?>"></div>
                <div class="field"><label for="country"><?= e(__('country')) ?></label><input id="country" name="country" maxlength="2" value="<?= e($profile['country']) ?>"></div>
            </div>
            <div class="field"><label for="notes"><?= e(__('internal_notes')) ?></label><textarea id="notes" name="notes" style="min-height:70px" placeholder="<?= e(__('internal_notes_help')) ?>"><?= e((string) ($client['notes'] ?? '')) ?></textarea></div>
            <button class="btn" type="submit"><?= e(__('save')) ?></button>
            </fieldset>
        </form>

        <div class="card">
            <h3 class="card-title"><?= icon('server') ?><?= e(__('services')) ?> (<?= count($services) ?>)</h3>
            <?php if ($services === []): ?>
                <p class="muted" style="margin:0"><?= e(__('client_has_no_services')) ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th><?= e(__('name')) ?></th><th><?= e(__('status')) ?></th><th class="num"><?= e(__('price')) ?></th><th><?= e(__('next_due')) ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($services as $service): ?>
                            <tr>
                                <td><?= (int) $service['id'] ?></td>
                                <td><?= e((string) $service['name']) ?></td>
                                <td><?= status_badge((string) $service['status']) ?></td>
                                <td class="num"><?= e(money((int) $service['price_cents'])) ?> / <?= e(__((string) $service['cycle'])) ?></td>
                                <td><?= e(fmt_date($service['next_due_at'] ?? null, 'd/m/Y')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3 class="card-title"><?= icon('receipt') ?><?= e(__('invoices')) ?></h3>
            <?php if ($invoices === []): ?>
                <p class="muted" style="margin:0"><?= e(__('no_invoices')) ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th><?= e(__('number')) ?></th><th><?= e(__('status')) ?></th><th class="num"><?= e(__('total')) ?></th><th><?= e(__('date')) ?></th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td class="mono"><?= e((string) $invoice['number']) ?></td>
                                <td><?= status_badge((string) $invoice['status']) ?></td>
                                <td class="num"><?= e(money((int) $invoice['total_cents'])) ?></td>
                                <td><?= e(fmt_date((string) $invoice['created_at'], 'd/m/Y')) ?></td>
                                <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(url($adminBase . '/invoice/' . (int) $invoice['id'] . '/pdf')) ?>">PDF</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <aside class="stack">
        <div class="card">
            <h3 class="card-title"><?= icon('list') ?><?= e(__('summary')) ?></h3>
            <table class="kv">
                <tr><th><?= e(__('registered')) ?></th><td><?= e(fmt_date((string) ($client['created_at'] ?? ''), 'd/m/Y')) ?></td></tr>
                <tr><th><?= e(__('last_login')) ?></th><td><?= !empty($client['last_login_at']) ? e(fmt_date((string) $client['last_login_at'], 'd/m/Y H:i')) . '<br><small class="muted mono">' . e((string) ($client['last_login_ip'] ?? '')) . '</small>' : '—' ?></td></tr>
                <tr><th><?= e(__('wallet')) ?></th><td><?= e(money((int) $wallet)) ?></td></tr>
                <tr><th>2FA</th><td><?= (int) $client['totp_enabled'] === 1 ? '<span class="badge badge-ok">' . e(__('enabled')) . '</span>' : '<span class="badge badge-muted">' . e(__('disabled')) . '</span>' ?></td></tr>
                <tr><th><?= e(__('active_sessions')) ?></th><td><?= (int) $sessions ?></td></tr>
            </table>
        </div>

        <?php if ($canManage): ?>
        <div class="card">
            <h3 class="card-title"><?= icon('shield') ?><?= e(__('account_access')) ?></h3>
            <p class="muted" style="font-size:.86rem"><?= e(__('account_access_help')) ?></p>
            <form method="post" action="<?= e(url($base . '/status')) ?>" class="stack" style="gap:8px">
                <?= csrf_field() ?>
                <div class="field" style="margin:0"><label for="reason"><?= e(__('reason_optional')) ?></label><input id="reason" name="reason" maxlength="255" placeholder="<?= e(__('reason_placeholder')) ?>"></div>
                <?php if ($status !== 'active'): ?>
                    <button class="btn" type="submit" name="status" value="active"><?= e(__('reactivate_account')) ?></button>
                <?php endif; ?>
                <?php if ($status !== 'blocked' && !$isSelf): ?>
                    <button class="btn btn-danger" type="submit" name="status" value="blocked"><?= e(__('block_account')) ?></button>
                <?php endif; ?>
                <?php if ($status !== 'disabled' && !$isSelf): ?>
                    <button class="btn btn-ghost" type="submit" name="status" value="disabled"><?= e(__('disable_account')) ?></button>
                <?php endif; ?>
                <?php if ($locked && $status === 'active'): ?>
                    <button class="btn btn-ghost" type="submit" name="status" value="active"><?= e(__('unlock_login')) ?></button>
                <?php endif; ?>
            </form>
        </div>

        <form class="card" method="post" action="<?= e(url($base . '/password')) ?>">
            <?= csrf_field() ?>
            <h3 class="card-title"><?= icon('settings') ?><?= e(__('reset_password')) ?></h3>
            <div class="field">
                <label for="password"><?= e(__('new_password')) ?></label>
                <input id="password" name="password" type="text" minlength="10" autocomplete="off" placeholder="<?= e(__('leave_empty_to_generate')) ?>">
            </div>
            <label class="checkbox" style="margin-bottom:12px"><input type="checkbox" name="disable_2fa" value="1"><span><?= e(__('also_disable_2fa')) ?></span></label>
            <button class="btn btn-ghost btn-block" type="submit"><?= e(__('set_password')) ?></button>
        </form>

        <form class="card" method="post" action="<?= e(url($adminBase . '/clients/' . (int) $client['id'] . '/credit')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="client">
            <h3 class="card-title"><?= icon('wallet') ?><?= e(__('wallet_credit')) ?></h3>
            <div class="form-actions" style="margin:0">
                <div class="input-money" style="flex:1"><input name="amount" inputmode="decimal" data-money placeholder="10,00" aria-label="<?= e(__('wallet_credit_amount')) ?>" required></div>
                <button class="btn" type="submit"><?= e(__('add')) ?></button>
            </div>
        </form>

        <?php if (!$isSelf): ?>
        <form class="card" method="post" action="<?= e(url($base . '/delete')) ?>" style="border-color:color-mix(in srgb, #dc2626 40%, var(--border))">
            <?= csrf_field() ?>
            <h3 class="card-title" style="color:var(--bad)"><?= icon('logout') ?><?= e(__('delete_account')) ?></h3>
            <p class="muted" style="font-size:.86rem"><?= e(__('admin_delete_help')) ?></p>
            <div class="field">
                <label for="confirm_username"><?= e(__('type_username_to_confirm', ['username' => (string) $client['username']])) ?></label>
                <input id="confirm_username" name="confirm_username" autocomplete="off" required>
            </div>
            <button class="btn btn-danger btn-block" type="submit"><?= e(__('delete_account')) ?></button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </aside>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
