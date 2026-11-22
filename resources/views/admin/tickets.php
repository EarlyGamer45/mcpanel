<?php $pageTitle = __('tickets'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<?php $adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/'); ?>
<section>
    <h1><?= e(__('tickets')) ?></h1>
    <p class="muted"><?= e(__('admin_tickets_help')) ?></p>

    <form method="get" action="<?= e(url($adminBase . '/tickets')) ?>" class="card" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div class="field" style="margin:0">
            <label for="status"><?= e(__('status')) ?></label>
            <select id="status" name="status">
                <option value=""><?= e(__('all')) ?></option>
                <?php foreach (['open', 'waiting_staff', 'waiting_customer', 'closed'] as $value): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(__($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="margin:0">
            <label for="department"><?= e(__('department')) ?></label>
            <select id="department" name="department">
                <option value=""><?= e(__('all')) ?></option>
                <?php foreach (['technical', 'billing', 'sales'] as $value): ?>
                    <option value="<?= e($value) ?>" <?= $department === $value ? 'selected' : '' ?>><?= e(__('dept_' . $value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit"><?= e(__('search')) ?></button>
    </form>

    <div class="table-wrap" style="margin-top:16px">
        <table>
            <thead><tr><th><?= e(__('number')) ?></th><th><?= e(__('subject')) ?></th><th><?= e(__('client')) ?></th><th><?= e(__('department')) ?></th><th><?= e(__('priority')) ?></th><th><?= e(__('status')) ?></th><th><?= e(__('date')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($tickets as $ticket): ?>
                <tr>
                    <td class="mono"><a href="<?= e(url($adminBase . '/tickets/' . (int) $ticket['id'])) ?>"><?= e((string) $ticket['number']) ?></a></td>
                    <td><?= e((string) $ticket['subject']) ?></td>
                    <td><?= e((string) $ticket['username']) ?></td>
                    <td><?= e(__('dept_' . (string) $ticket['department'])) ?></td>
                    <td><?= e(__('prio_' . (string) $ticket['priority'])) ?></td>
                    <td><?= status_badge((string) $ticket['status']) ?></td>
                    <td><small><?= e(fmt_date((string) $ticket['updated_at'])) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($tickets === []): ?><tr><td colspan="7" class="muted"><?= e(__('no_tickets')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
