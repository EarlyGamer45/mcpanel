<?php $pageTitle = __('knowledge_base'); include __DIR__ . '/partials/head.php'; ?>
<section>
    <h1><?= e(__('knowledge_base')) ?></h1>
    <form method="get" action="<?= e(url('/knowledge')) ?>" class="card" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div class="field" style="flex:1;margin:0">
            <label for="q"><?= e(__('search')) ?></label>
            <input id="q" name="q" value="<?= e($term) ?>" placeholder="<?= e(__('search_placeholder')) ?>">
        </div>
        <button class="btn" type="submit"><?= e(__('search')) ?></button>
    </form>
    <div class="grid grid-2" style="margin-top:20px">
        <?php foreach ($articles as $article): ?>
            <article class="card">
                <span class="pill pill-muted"><?= e((string) $article['category']) ?></span>
                <h3 style="margin-top:10px"><?= e((string) $article['title']) ?></h3>
                <div class="muted" style="font-size:.9rem"><?= $article['body'] ?></div>
            </article>
        <?php endforeach; ?>
        <?php if ($articles === []): ?>
            <p class="muted"><?= e(__('no_results')) ?></p>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
