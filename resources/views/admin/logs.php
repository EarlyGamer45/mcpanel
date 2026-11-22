<?php $pageTitle = __('logs'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<section>
    <h1><?= e(__('logs')) ?></h1>

    <h2><?= e(__('audit_log')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('date')) ?></th><th><?= e(__('action')) ?></th><th><?= e(__('actor')) ?></th><th>IP</th><th><?= e(__('details')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($audit as $row): ?>
                <tr>
                    <td><?= e(fmt_date((string) $row['created_at'])) ?></td>
                    <td class="mono"><?= e((string) $row['action']) ?></td>
                    <td><?= e((string) ($row['actor_id'] ?? 'system')) ?></td>
                    <td class="mono"><?= e((string) ($row['ip'] ?? '')) ?></td>
                    <td><small class="muted"><?= e(\App\Core\Str::limit((string) ($row['meta_json'] ?? ''), 90)) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:32px"><?= e(__('provisioning')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('date')) ?></th><th><?= e(__('service')) ?></th><th><?= e(__('step')) ?></th><th><?= e(__('result')) ?></th><th><?= e(__('message')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($provisioning as $row): ?>
                <tr>
                    <td><?= e(fmt_date((string) $row['created_at'])) ?></td>
                    <td>#<?= (int) $row['service_id'] ?></td>
                    <td class="mono"><?= e((string) $row['step']) ?></td>
                    <td><?= (int) $row['success'] === 1 ? '<span class="badge badge-ok">ok</span>' : '<span class="badge badge-bad">ko</span>' ?></td>
                    <td><small class="muted"><?= e(\App\Core\Str::limit((string) $row['message'], 100)) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:32px"><?= e(__('webhooks')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('date')) ?></th><th>Gateway</th><th><?= e(__('event')) ?></th><th><?= e(__('processed')) ?></th><th><?= e(__('error')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($webhooks as $row): ?>
                <tr>
                    <td><?= e(fmt_date((string) $row['created_at'])) ?></td>
                    <td><?= e((string) $row['gateway']) ?></td>
                    <td class="mono"><?= e(\App\Core\Str::limit((string) $row['event_type'], 40)) ?></td>
                    <td><?= $row['processed_at'] ? e(fmt_date((string) $row['processed_at'])) : '<span class="badge badge-warn">pending</span>' ?></td>
                    <td><small class="muted"><?= e((string) ($row['error'] ?? '')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:32px"><?= e(__('emails')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('date')) ?></th><th><?= e(__('subject')) ?></th><th><?= e(__('status')) ?></th><th><?= e(__('error')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($mails as $row): ?>
                <tr>
                    <td><?= e(fmt_date((string) $row['created_at'])) ?></td>
                    <td><?= e((string) $row['subject']) ?></td>
                    <td><?= (string) $row['status'] === 'sent' ? '<span class="badge badge-ok">sent</span>' : '<span class="badge badge-bad">failed</span>' ?></td>
                    <td><small class="muted"><?= e((string) ($row['error'] ?? '')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:32px"><?= e(__('failed_jobs')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('date')) ?></th><th>Job</th><th><?= e(__('attempts')) ?></th><th><?= e(__('error')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $row): ?>
                <tr>
                    <td><?= e(fmt_date((string) $row['failed_at'])) ?></td>
                    <td class="mono"><?= e((string) $row['job']) ?></td>
                    <td><?= (int) $row['attempts'] ?></td>
                    <td><small class="muted"><?= e(\App\Core\Str::limit((string) $row['last_error'], 120)) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
