<?php $pageTitle = (string) $ticket['number']; include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<?php $adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/'); ?>
<section>
    <p><a href="<?= e(url($adminBase . '/tickets')) ?>">← <?= e(__('tickets')) ?></a></p>
    <h1><?= e((string) $ticket['number']) ?> · <?= e((string) $ticket['subject']) ?></h1>
    <p class="muted">
        <?= e($customer['email'] ?? '') ?> · <?= e(__('dept_' . (string) $ticket['department'])) ?> ·
        <?= e(__('prio_' . (string) $ticket['priority'])) ?> · <?= status_badge((string) $ticket['status']) ?>
    </p>

    <div class="stack" style="margin:16px 0">
        <?php foreach ($messages as $message): ?>
            <?php $isStaff = (int) $message['user_id'] !== (int) $ticket['user_id']; ?>
            <article class="card" style="margin:0;<?= $isStaff ? 'border-left:3px solid var(--brand)' : '' ?>">
                <header style="display:flex;justify-content:space-between;gap:12px;align-items:center">
                    <strong><?= $isStaff ? e(__('support_team')) : e((string) ($username ?: __('client'))) ?></strong>
                    <small class="muted"><?= e(fmt_date((string) $message['created_at'])) ?></small>
                </header>
                <?php if ((int) $message['internal'] === 1): ?>
                    <span class="badge badge-warn"><?= e(__('internal_note')) ?></span>
                <?php endif; ?>
                <p style="white-space:pre-wrap;margin-top:10px"><?= e((string) $message['body']) ?></p>
                <?php if (!empty($attachments[(int) $message['id']])): ?>
                    <ul style="margin:8px 0 0;padding-left:18px">
                        <?php foreach ($attachments[(int) $message['id']] as $attachment): ?>
                            <li><a href="<?= e(url($adminBase . '/attachment/' . (int) $attachment['id'])) ?>"><?= e((string) $attachment['filename']) ?></a>
                                <small class="muted">(<?= e(\App\Core\Str::bytes((int) $attachment['size'])) ?>)</small></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ((string) $ticket['status'] !== 'closed'): ?>
        <form method="post" action="<?= e(url($adminBase . '/tickets/' . (int) $ticket['id'] . '/reply')) ?>" class="card" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field">
                <label for="body"><?= e(__('reply')) ?></label>
                <textarea id="body" name="body" rows="5" required></textarea>
            </div>
            <div class="field">
                <label for="attachment"><?= e(__('attachment')) ?></label>
                <input id="attachment" name="attachment" type="file" accept="image/png,image/jpeg,image/webp,application/pdf,text/plain,.zip,.gz">
                <small class="muted"><?= e(__('attachment_help')) ?></small>
            </div>
            <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
                <input type="checkbox" name="internal" value="1">
                <?= e(__('internal_note_help')) ?>
            </label>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn" type="submit"><?= e(__('send_reply')) ?></button>
                <button class="btn btn-ghost" type="submit" form="close-ticket"><?= e(__('close_ticket')) ?></button>
            </div>
        </form>
        <form id="close-ticket" method="post" action="<?= e(url($adminBase . '/tickets/' . (int) $ticket['id'] . '/close')) ?>" onsubmit="return confirm('<?= e(__('confirm_close_ticket')) ?>')">
            <?= csrf_field() ?>
        </form>
    <?php else: ?>
        <div class="alert"><?= e(__('ticket_closed')) ?></div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
