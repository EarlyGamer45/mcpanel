<?php
$isArticle = $type === 'article';
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$action = $adminBase . '/content/' . ($isArticle ? 'articles/' . (int) $item['id'] : 'announcements/' . (int) $item['id']);
$pageTitle = $isArticle ? __('edit_article') : __('edit_announcement');
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/admin_nav.php';
?>
<section>
    <p><a href="<?= e(url($adminBase . '/content')) ?>">&larr; <?= e(__('content')) ?></a></p>
    <h1><?= e($pageTitle) ?></h1>
    <form method="post" action="<?= e(url($action)) ?>" class="card">
        <?= csrf_field() ?>
        <label><?= e(__('title')) ?><input type="text" name="title" required maxlength="190" value="<?= e((string) $item['title']) ?>"></label>
        <?php if ($isArticle): ?>
            <div class="grid grid-2">
                <label><?= e(__('category')) ?><input type="text" name="category" required maxlength="80" value="<?= e((string) $item['category']) ?>"></label>
                <label><?= e(__('locale')) ?>
                    <select name="locale">
                        <option value="it" <?= (string) $item['locale'] === 'it' ? 'selected' : '' ?>>Italiano</option>
                        <option value="en" <?= (string) $item['locale'] === 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                </label>
                <label><?= e(__('slug')) ?><input type="text" name="slug" maxlength="190" value="<?= e((string) $item['slug']) ?>"></label>
            </div>
        <?php else: ?>
            <div class="grid grid-2">
                <label><?= e(__('level')) ?>
                    <select name="level">
                        <?php foreach (['info', 'success', 'warning', 'danger'] as $level): ?>
                            <option value="<?= e($level) ?>" <?= (string) $item['level'] === $level ? 'selected' : '' ?>><?= e($level) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?= e(__('expires_at')) ?><input type="date" name="expires_at" value="<?= e(substr((string) ($item['expires_at'] ?? ''), 0, 10)) ?>"></label>
            </div>
        <?php endif; ?>
        <label><?= e(__('body')) ?><textarea name="body" rows="8" required><?= e((string) $item['body']) ?></textarea></label>
        <?php if ($isArticle): ?>
            <label class="check"><input type="checkbox" name="published" value="1" <?= (int) $item['published'] === 1 ? 'checked' : '' ?>> <?= e(__('published')) ?></label>
        <?php endif; ?>
        <button class="btn" type="submit"><?= e(__('save')) ?></button>
    </form>
</section>
<?php include __DIR__ . '/../partials/foot.php'; ?>
