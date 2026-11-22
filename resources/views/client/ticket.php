<?php $pageTitle = (string) $ticket['subject']; include __DIR__ . '/../partials/head.php'; ?>
<section style="max-width:820px">
    <p class="muted"><a href="<?= e(url('/client/tickets')) ?>"><?= e(__('tickets')) ?></a> / <?= e((string) $ticket['number']) ?></p>
    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:center">
        <h1 style="margin:0"><?= e((string) $ticket['subject']) ?></h1>
        <?= status_badge((string) $ticket['status']) ?>
    </div>
    <p class="muted" style="font-size:.85rem"><?= e(__('dept_' . (string) $ticket['department'])) ?> · <?= e(fmt_date((string) $ticket['created_at'])) ?></p>

    <div style="display:grid;gap:12px;margin:20px 0">
        <?php foreach ($messages as $message): ?>
            <?php $staff = (int) $message['user_id'] !== (int) $ticket['user_id']; ?>
            <div class="card <?= $staff ? 'card-flat' : '' ?>">
                <div style="display:flex;justify-content:space-between;gap:12px" class="muted">
                    <strong><?= $staff ? e(__('support_team')) : e((string) ($currentUser['username'] ?? '')) ?></strong>
                    <small><?= e(fmt_date((string) $message['created_at'])) ?></small>
                </div>
                <p style="margin:8px 0 0;white-space:pre-line"><?= e((string) $message['body']) ?></p>
                <?php if (!empty($attachments[(int) $message['id']])): ?>
                    <ul style="margin:8px 0 0;padding-left:18px">
                        <?php foreach ($attachments[(int) $message['id']] as $attachment): ?>
                            <li><a href="<?= e(url('/client/attachment/' . (int) $attachment['id'])) ?>"><?= e((string) $attachment['filename']) ?></a>
                                <small class="muted">(<?= e(\App\Core\Str::bytes((int) $attachment['size'])) ?>)</small></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($ticket['status'] !== 'closed'): ?>
        <form method="post" action="<?= e(url('/client/ticket/' . (int) $ticket['id'] . '/reply')) ?>" class="card" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field">
                <label for="body"><?= e(__('reply')) ?></label>
                <textarea id="body" name="body" required></textarea>
            </div>
            <div class="field">
                <label for="attachment"><?= e(__('attachment')) ?></label>
                <input id="attachment" name="attachment" type="file" accept="image/png,image/jpeg,image/webp,application/pdf,text/plain,.zip,.gz">
                <small class="muted"><?= e(__('attachment_help')) ?></small>
            </div>
            <button class="btn" type="submit"><?= e(__('send_reply')) ?></button>
        </form>
    <?php else: ?>
        <div class="alert"><?= e(__('ticket_closed')) ?></div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
