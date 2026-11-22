<?php $pageTitle = __('orders'); include __DIR__ . '/../partials/head.php'; ?>
<?php
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$isPaid = static fn (array $order): bool => in_array((string) $order['status'], ['paid', 'provisioning', 'active', 'refunded'], true);
?>
<div class="page-head">
    <div>
        <h1><?= e(__('orders')) ?></h1>
        <p><?= e(__('admin_orders_help')) ?></p>
    </div>
    <button class="btn btn-danger" type="submit" form="orders-delete" onclick="return confirm('<?= e(__('orders_delete_confirm')) ?>')"><?= e(__('delete_selected')) ?></button>
</div>

<form id="orders-delete" method="post" action="<?= e(url($adminBase . '/orders/delete')) ?>"><?= csrf_field() ?></form>

<div class="table-wrap">
    <table>
        <thead><tr>
            <th style="width:34px"><input type="checkbox" data-check-all aria-label="<?= e(__('select_all')) ?>"></th>
            <th><?= e(__('number')) ?></th><th><?= e(__('client')) ?></th><th><?= e(__('invoice')) ?></th><th class="num"><?= e(__('total')) ?></th><th><?= e(__('status')) ?></th><th>Risk</th><th><?= e(__('date')) ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td>
                    <input type="checkbox" name="ids[]" value="<?= (int) $order['id'] ?>" form="orders-delete" data-check-item aria-label="<?= e((string) $order['number']) ?>">
                </td>
                <td class="mono"><?= e((string) $order['number']) ?></td>
                <td><a href="<?= e(url($adminBase . '/clients/' . (int) $order['user_id'])) ?>"><?= e((string) $order['username']) ?></a></td>
                <td><?= e((string) ($order['invoice_number'] ?? '—')) ?></td>
                <td class="num"><?= e(money((int) $order['total_cents'], (string) $order['currency'])) ?></td>
                <td><?= status_badge((string) $order['status']) ?></td>
                <td><small class="muted"><?= (int) $order['risk_score'] ?></small></td>
                <td><?= e(fmt_date((string) $order['created_at'], 'd/m/Y H:i')) ?></td>
                <td class="actions">
                    <?php if (in_array((string) $order['status'], ['pending_payment', 'review'], true)): ?>
                        <form method="post" action="<?= e(url($adminBase . '/orders/' . (int) $order['id'] . '/confirm')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm" type="submit" onclick="return confirm('<?= e(__('confirm_payment_question')) ?>')"><?= e(__('confirm_payment')) ?></button>
                        </form>
                    <?php endif; ?>
                    <button class="btn btn-danger btn-sm" type="submit" form="orders-delete" name="single" value="<?= (int) $order['id'] ?>" onclick="return confirm('<?= e(__($isPaid($order) ? 'order_delete_paid_confirm' : 'order_delete_confirm', ['number' => (string) $order['number']])) ?>')"><?= e(__('delete')) ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($orders === []): ?><tr><td colspan="9" class="muted"><?= e(__('no_results')) ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<p class="muted" style="font-size:.86rem;margin-top:12px"><?= e(__('orders_delete_help')) ?></p>
<?php include __DIR__ . '/../partials/foot.php'; ?>
