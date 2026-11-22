<?php $pageTitle = __('content'); include __DIR__ . '/../partials/head.php'; include __DIR__ . '/../partials/admin_nav.php'; ?>
<section>
    <h1><?= e(__('content')) ?></h1>
    <p class="muted"><?= e(__('content_help')) ?></p>

    <h2 style="margin-top:24px"><?= e(__('kb_articles')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('title')) ?></th><th><?= e(__('category')) ?></th><th><?= e(__('locale')) ?></th><th><?= e(__('slug')) ?></th><th><?= e(__('status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($articles as $article): ?>
                <tr>
                    <td><?= e((string) $article['title']) ?></td>
                    <td><?= e((string) $article['category']) ?></td>
                    <td class="mono"><?= e(strtoupper((string) $article['locale'])) ?></td>
                    <td class="mono"><?= e((string) $article['slug']) ?></td>
                    <td><span class="badge <?= (int) $article['published'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $article['published'] === 1 ? e(__('published')) : e(__('draft')) ?></span></td>
                    <td>
                        <a class="btn btn-small btn-secondary" href="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content/articles/' . (int) $article['id'] . '/edit')) ?>"><?= e(__('edit')) ?></a>
                        <form method="post" action="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content/articles/' . (int) $article['id'] . '/toggle')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-small btn-secondary" type="submit"><?= (int) $article['published'] === 1 ? e(__('unpublish')) : e(__('publish')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($articles === []): ?><tr><td colspan="6" class="muted"><?= e(__('no_results')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <details style="margin-top:16px">
        <summary class="btn btn-secondary"><?= e(__('new_article')) ?></summary>
        <form method="post" action="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content/articles')) ?>" class="card" style="margin-top:12px">
            <?= csrf_field() ?>
            <div class="grid grid-2">
                <label><?= e(__('title')) ?><input type="text" name="title" required maxlength="190"></label>
                <label><?= e(__('category')) ?><input type="text" name="category" required maxlength="80" placeholder="Billing"></label>
                <label><?= e(__('locale')) ?>
                    <select name="locale"><option value="en">English</option><option value="it">Italiano</option></select>
                </label>
                <label><?= e(__('slug')) ?><input type="text" name="slug" maxlength="190" placeholder="auto"></label>
            </div>
            <label><?= e(__('body')) ?><textarea name="body" rows="6" required></textarea></label>
            <label class="check"><input type="checkbox" name="published" value="1" checked> <?= e(__('publish')) ?></label>
            <button class="btn" type="submit"><?= e(__('save')) ?></button>
        </form>
    </details>

    <h2 style="margin-top:32px"><?= e(__('announcements')) ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= e(__('title')) ?></th><th><?= e(__('level')) ?></th><th><?= e(__('published_at')) ?></th><th><?= e(__('expires_at')) ?></th><th><?= e(__('status')) ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($announcements as $announcement): ?>
                <tr>
                    <td><?= e((string) $announcement['title']) ?></td>
                    <td><span class="badge"><?= e((string) $announcement['level']) ?></span></td>
                    <td class="mono"><?= e((string) ($announcement['published_at'] ?? '—')) ?></td>
                    <td class="mono"><?= e((string) ($announcement['expires_at'] ?? '—')) ?></td>
                    <td><span class="badge <?= (int) $announcement['visible'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $announcement['visible'] === 1 ? e(__('visible')) : e(__('hidden')) ?></span></td>
                    <td>
                        <a class="btn btn-small btn-secondary" href="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content/announcements/' . (int) $announcement['id'] . '/edit')) ?>"><?= e(__('edit')) ?></a>
                        <form method="post" action="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content/announcements/' . (int) $announcement['id'] . '/toggle')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-small btn-secondary" type="submit"><?= (int) $announcement['visible'] === 1 ? e(__('hide')) : e(__('show')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($announcements === []): ?><tr><td colspan="6" class="muted"><?= e(__('no_results')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <details style="margin-top:16px">
        <summary class="btn btn-secondary"><?= e(__('new_announcement')) ?></summary>
        <form method="post" action="<?= e(url('/' . trim((string) config('security.admin_path', 'admin'), '/') . '/content/announcements')) ?>" class="card" style="margin-top:12px">
            <?= csrf_field() ?>
            <label><?= e(__('title')) ?><input type="text" name="title" required maxlength="190"></label>
            <div class="grid grid-2">
                <label><?= e(__('level')) ?>
                    <select name="level">
                        <option value="info">info</option>
                        <option value="success">success</option>
                        <option value="warning">warning</option>
                        <option value="danger">danger</option>
                    </select>
                </label>
                <label><?= e(__('expires_at')) ?><input type="date" name="expires_at"></label>
            </div>
            <label><?= e(__('body')) ?><textarea name="body" rows="4" required></textarea></label>
            <button class="btn" type="submit"><?= e(__('publish')) ?></button>
        </form>
    </details>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
