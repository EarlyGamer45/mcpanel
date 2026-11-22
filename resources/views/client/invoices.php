<?php $pageTitle = __('invoices'); include __DIR__ . '/../partials/head.php'; ?>
<section>
    <h1><?= e(__('invoices')) ?></h1>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('number')) ?></th><th><?= e(__('date')) ?></th><th><?= e(__('due_date')) ?></th><th class="num"><?= e(__('total')) ?></th><th><?= e(__('status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td><?= e((string) $invoice['number']) ?></td>
                    <td><?= e(fmt_date((string) $invoice['created_at'], 'd/m/Y')) ?></td>
                    <td><?= e(fmt_date((string) $invoice['due_at'], 'd/m/Y')) ?></td>
                    <td class="num"><?= e(money((int) $invoice['total_cents'], (string) $invoice['currency'])) ?></td>
                    <td><?= status_badge((string) $invoice['status']) ?></td>
                    <td class="actions">
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('/client/invoice/' . (int) $invoice['id'] . '/pdf')) ?>">PDF</a>
                        <?php if ($invoice['status'] === 'pending' && $invoice['order_id'] !== null): ?>
                            <form method="post" action="<?= e(url('/client/invoice/' . (int) $invoice['id'] . '/pay')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm" type="submit"><?= e(__('pay_now')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($invoices === []): ?><tr><td colspan="6" class="muted"><?= e(__('no_invoices')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
