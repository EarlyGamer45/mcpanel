<?php $pageTitle = (string) $document['name']; include __DIR__ . '/partials/head.php'; ?>
<section style="max-width:820px">
    <h1><?= e(__($name)) ?></h1>
    <p class="muted"><?= e(__('version')) ?> <?= e((string) $document['version']) ?> · <?= e(fmt_date((string) $document['updated_at'], 'd/m/Y')) ?></p>
    <article class="card">
        <?= $document['body'] ?>
    </article>
    <p class="muted" style="font-size:.82rem;margin-top:16px"><?= e(__('legal_placeholder_2')) ?></p>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
