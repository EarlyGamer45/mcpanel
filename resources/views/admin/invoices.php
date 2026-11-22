<?php
$pageTitle = __('invoices');
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$query = array_filter(['status' => (string) $status, 'q' => (string) $term], static fn ($v) => $v !== '');
$exportUrl = url($adminBase . '/invoices/export') . ($query === [] ? '' : '?' . http_build_query($query));
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/admin_nav.php';
?>
<section>
    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:center">
        <h1 style="margin:0"><?= e(__('invoices')) ?></h1>
        <a class="btn btn-secondary" href="<?= e($exportUrl) ?>"><?= e(__('export_csv')) ?></a>
    </div>
    <p class="muted"><?= e(__('admin_invoices_help')) ?></p>

    <div class="grid grid-3" style="margin:16px 0">
        <div class="stat"><span><?= e(__('invoices')) ?></span><strong><?= (int) $summary['count'] ?></strong></div>
        <div class="stat"><span><?= e(__('total')) ?></span><strong><?= e(money((int) $summary['total'])) ?></strong></div>
        <div class="stat"><span><?= e(__('paid')) ?></span><strong><?= e(money((int) $summary['paid_total'])) ?></strong></div>
    </div>

    <form method="get" action="<?= e(url($adminBase . '/invoices')) ?>" class="card" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
        <label style="flex:1;min-width:220px"><?= e(__('search')) ?>
            <input type="search" name="q" value="<?= e((string) $term) ?>" placeholder="<?= e(__('search_invoices_placeholder')) ?>">
        </label>
        <label><?= e(__('status')) ?>
            <select name="status">
                <option value=""><?= e(__('all_statuses')) ?></option>
                <?php foreach (['pending', 'paid', 'overdue', 'void'] as $option): ?>
                    <option value="<?= e($option) ?>" <?= (string) $status === $option ? 'selected' : '' ?>><?= e(__($option)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn" type="submit"><?= e(__('filter')) ?></button>
    </form>

    <div class="table-wrap" style="margin-top:16px">
        <table>
            <thead>
                <tr>
                    <th><?= e(__('number')) ?></th><th><?= e(__('client')) ?></th><th><?= e(__('status')) ?></th>
                    <th class="num"><?= e(__('total')) ?></th><th><?= e(__('issued_on')) ?></th><th><?= e(__('due_date')) ?></th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td class="mono"><?= e((string) $invoice['number']) ?></td>
                    <td><?= e((string) $invoice['username']) ?><br><small class="muted"><?= e((string) $invoice['email']) ?></small></td>
                    <td><?= status_badge((string) $invoice['status']) ?></td>
                    <td class="num"><?= e(money((int) $invoice['total_cents'], (string) $invoice['currency'])) ?></td>
                    <td><?= e(fmt_date((string) $invoice['created_at'], 'd/m/Y')) ?></td>
                    <td><?= e(fmt_date((string) ($invoice['due_at'] ?? ''), 'd/m/Y')) ?></td>
                    <td><a class="btn btn-small btn-secondary" href="<?= e(url($adminBase . '/invoice/' . (int) $invoice['id'] . '/pdf')) ?>">PDF</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($invoices === []): ?><tr><td colspan="7" class="muted"><?= e(__('no_results')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
