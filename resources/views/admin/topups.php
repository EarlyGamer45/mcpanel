<?php $pageTitle = __('wallet_topups'); include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$badges = ['pending' => 'badge-warn', 'paid' => 'badge-ok', 'rejected' => 'badge-bad'];
?>
<div class="page-head">
    <div>
        <h1><?= e(__('wallet_topups')) ?></h1>
        <p><?= e(__('wallet_topups_lead')) ?></p>
    </div>
</div>

<div class="grid grid-3" style="margin-bottom:16px">
    <div class="stat"><span><?= e(__('topups_waiting')) ?></span><strong><?= (int) $counts['pending'] ?></strong></div>
    <div class="stat"><span><?= e(__('topups_waiting_amount')) ?></span><strong><?= e(money((int) $counts['pending_cents'])) ?></strong></div>
    <div class="stat"><span>IBAN</span><strong class="mono" style="font-size:.95rem"><?= e((string) ($bank['iban'] ?? '') !== '' ? (string) $bank['iban'] : '—') ?></strong></div>
</div>

<div class="tabs">
    <?php foreach (['pending' => __('topups_tab_pending'), 'paid' => __('topups_tab_paid'), 'rejected' => __('topups_tab_rejected'), 'all' => __('all')] as $key => $label): ?>
        <a href="<?= e(url($adminBase . '/topups?status=' . $key)) ?>" class="<?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?><?php if ($key === 'pending' && (int) $counts['pending'] > 0): ?> (<?= (int) $counts['pending'] ?>)<?php endif; ?></a>
    <?php endforeach; ?>
</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th><?= e(__('reference')) ?></th>
            <th><?= e(__('client')) ?></th>
            <th class="num"><?= e(__('requested_amount')) ?></th>
            <th><?= e(__('date')) ?></th>
            <th><?= e(__('status')) ?></th>
            <th style="min-width:360px"><?= e(__('actions')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($topups as $topup): ?>
            <?php $topupStatus = (string) $topup['status']; ?>
            <tr>
                <td class="mono"><?= e((string) $topup['reference']) ?></td>
                <td>
                    <a href="<?= e(url($adminBase . '/clients/' . (int) $topup['user_id'])) ?>" style="color:var(--text);font-weight:600"><?= e((string) $topup['username']) ?></a><br>
                    <small class="muted"><?= e((string) $topup['email']) ?></small>
                </td>
                <td class="num"><strong><?= e(money((int) $topup['amount_cents'])) ?></strong></td>
                <td>
                    <?= e(fmt_date((string) $topup['created_at'], 'd/m/Y H:i')) ?>
                    <?php if (!empty($topup['paid_at'])): ?><br><small class="muted"><?= e(__('credited_on')) ?> <?= e(fmt_date((string) $topup['paid_at'], 'd/m/Y H:i')) ?></small><?php endif; ?>
                </td>
                <td><span class="badge <?= $badges[$topupStatus] ?? 'badge-muted' ?>"><?= e(__('topup_status_' . $topupStatus)) ?></span></td>
                <td>
                    <?php if ($topupStatus === 'pending'): ?>
                        <form method="post" action="<?= e(url($adminBase . '/topups/' . (int) $topup['id'])) ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                            <?= csrf_field() ?>
                            <div class="input-money" style="width:120px"><input name="amount" inputmode="decimal" data-money value="<?= e(money_input((int) $topup['amount_cents'])) ?>" aria-label="<?= e(__('received_amount')) ?>" title="<?= e(__('received_amount_help')) ?>"></div>
                            <button class="btn btn-sm" type="submit" name="decision" value="accept"><?= icon('check', 'icon icon-sm') ?><?= e(__('accept_topup')) ?></button>
                            <input name="reason" maxlength="255" placeholder="<?= e(__('reject_reason_placeholder')) ?>" style="width:150px;padding:5px 8px;font-size:.84rem" aria-label="<?= e(__('reason')) ?>">
                            <button class="btn btn-danger btn-sm" type="submit" name="decision" value="reject"><?= e(__('reject_topup')) ?></button>
                        </form>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($topups === []): ?><tr><td colspan="6" class="muted"><?= e(__('no_topups_admin')) ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<p class="muted" style="font-size:.86rem;margin-top:12px"><?= e(__('topups_footer_help')) ?></p>
<?php include __DIR__ . '/../partials/foot.php'; ?>
