<?php $pageTitle = __('status_page'); include __DIR__ . '/partials/head.php'; ?>
<section>
    <h1><?= e(__('status_page')) ?></h1>
    <p class="muted"><?= e(__('status_lead')) ?></p>
    <?php foreach ($incidents as $incident): ?>
        <div class="alert <?= $incident['level'] === 'danger' ? 'alert-danger' : '' ?>">
            <strong><?= e((string) $incident['title']) ?></strong> — <?= e((string) $incident['body']) ?>
        </div>
    <?php endforeach; ?>
    <div class="table-wrap" style="margin-top:18px">
        <table>
            <thead><tr><th><?= e(__('node')) ?></th><th><?= e(__('location')) ?></th><th><?= e(__('protection')) ?></th><th><?= e(__('status')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($nodes as $node): ?>
                <tr>
                    <td class="mono"><?= e((string) $node['name']) ?></td>
                    <td><?= e((string) $node['location']) ?></td>
                    <td class="muted"><?= e((string) ($node['ddos_note'] ?? '—')) ?></td>
                    <td><?= (int) $node['maintenance'] === 1 ? '<span class="badge badge-warn">' . e(__('maintenance')) . '</span>' : '<span class="badge badge-ok">' . e(__('operational')) . '</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
