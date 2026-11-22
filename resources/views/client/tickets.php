<?php $pageTitle = __('tickets'); include __DIR__ . '/../partials/head.php'; ?>
<section>
    <h1><?= e(__('tickets')) ?></h1>
    <div class="two-col">
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= e(__('number')) ?></th><th><?= e(__('subject')) ?></th><th><?= e(__('department')) ?></th><th><?= e(__('status')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td class="mono"><?= e((string) $ticket['number']) ?></td>
                        <td><?= e((string) $ticket['subject']) ?></td>
                        <td><?= e(__('dept_' . (string) $ticket['department'])) ?></td>
                        <td><?= status_badge((string) $ticket['status']) ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="<?= e(url('/client/ticket/' . (int) $ticket['id'])) ?>"><?= e(__('open')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($tickets === []): ?><tr><td colspan="5" class="muted"><?= e(__('no_tickets')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <form method="post" action="<?= e(url('/client/tickets')) ?>" class="card" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <h3><?= e(__('new_ticket')) ?></h3>
            <div class="field">
                <label for="subject"><?= e(__('subject')) ?></label>
                <input id="subject" name="subject" required maxlength="200">
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="department"><?= e(__('department')) ?></label>
                    <select id="department" name="department">
                        <option value="technical"><?= e(__('dept_technical')) ?></option>
                        <option value="billing"><?= e(__('dept_billing')) ?></option>
                        <option value="sales"><?= e(__('dept_sales')) ?></option>
                    </select>
                </div>
                <div class="field">
                    <label for="priority"><?= e(__('priority')) ?></label>
                    <select id="priority" name="priority">
                        <option value="normal"><?= e(__('prio_normal')) ?></option>
                        <option value="high"><?= e(__('prio_high')) ?></option>
                        <option value="urgent"><?= e(__('prio_urgent')) ?></option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="service_id"><?= e(__('related_service')) ?></label>
                <select id="service_id" name="service_id">
                    <option value=""><?= e(__('none')) ?></option>
                    <?php foreach ($services as $service): ?>
                        <option value="<?= (int) $service['id'] ?>"><?= e((string) $service['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="body"><?= e(__('message')) ?></label>
                <textarea id="body" name="body" required></textarea>
            </div>
            <div class="field">
                <label for="attachment"><?= e(__('attachment')) ?></label>
                <input id="attachment" name="attachment" type="file" accept="image/png,image/jpeg,image/webp,application/pdf,text/plain,.zip,.gz">
                <small class="muted"><?= e(__('attachment_help')) ?></small>
            </div>
            <button class="btn btn-block" type="submit"><?= e(__('send')) ?></button>
        </form>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
