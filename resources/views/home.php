<?php
$pageTitle = __('home_title');
include __DIR__ . '/partials/head.php';
$heroBadge = $theme['hero_badge'] !== '' ? $theme['hero_badge'] : __('hero_badge');
$heroTitle = $theme['hero_title'] !== '' ? $theme['hero_title'] : __('hero_title');
$heroLead = $theme['hero_lead'] !== '' ? $theme['hero_lead'] : __('hero_lead');
$heroCta = $theme['hero_cta'] !== '' ? $theme['hero_cta'] : __('choose_plan');
$show = static fn (string $section): bool => \App\Domain\Theme::sectionEnabled($section, $theme);
?>

<section class="hero">
    <div>
        <span class="pill"><?= icon('bolt', 'icon icon-sm') ?><?= e($heroBadge) ?></span>
        <h1><?= e($heroTitle) ?></h1>
        <p class="lead"><?= e($heroLead) ?></p>
        <div class="hero-actions">
            <a class="btn btn-lg" href="<?= e(url('/catalog')) ?>"><?= e($heroCta) ?> <?= icon('arrow') ?></a>
            <a class="btn btn-ghost btn-lg" href="<?= e(url('/knowledge')) ?>"><?= icon('book') ?><?= e(__('read_guides')) ?></a>
        </div>
        <div class="hero-trust">
            <span><?= icon('check') ?><?= e(__('trust_instant')) ?></span>
            <span><?= icon('check') ?><?= e(__('trust_no_contract')) ?></span>
            <span><?= icon('check') ?><?= e(__('trust_support')) ?></span>
        </div>
    </div>
    <aside class="hero-panel">
        <h3><?= icon('shield') ?><?= e(__('included_every_plan')) ?></h3>
        <ul>
            <li><span><?= icon('shield') ?><?= e(__('feature_ddos')) ?></span><strong>1 Tbps</strong></li>
            <li><span><?= icon('disk') ?><?= e(__('feature_nvme')) ?></span><strong>NVMe</strong></li>
            <li><span><?= icon('cloud') ?><?= e(__('feature_backups')) ?></span><strong>3×</strong></li>
            <li><span><?= icon('chat') ?><?= e(__('feature_support')) ?></span><strong>24/7</strong></li>
            <li><span><?= icon('server') ?><?= e(__('feature_panel')) ?></span><strong>Pterodactyl</strong></li>
        </ul>
    </aside>
</section>

<?php if ($show('announcements') && $announcements !== []): ?>
<section>
    <div class="grid grid-2">
        <?php foreach ($announcements as $announcement): ?>
            <div class="alert <?= $announcement['level'] === 'danger' ? 'alert-danger' : ($announcement['level'] === 'warning' ? '' : 'alert-info') ?>" style="margin:0">
                <strong><?= e((string) $announcement['title']) ?></strong><br>
                <?= e((string) $announcement['body']) ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($show('plans')): ?>
<section>
    <div class="section-head">
        <div>
            <span class="eyebrow"><?= e(__('catalog')) ?></span>
            <h2><?= e(__('popular_plans')) ?></h2>
            <p class="muted"><?= e(__('popular_plans_lead')) ?></p>
        </div>
        <a class="btn btn-ghost" href="<?= e(url('/catalog')) ?>"><?= e(__('see_all_plans')) ?> <?= icon('arrow') ?></a>
    </div>
    <div class="grid grid-3">
        <?php foreach ($products as $product): ?>
            <?php include __DIR__ . '/partials/plan_card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($show('features')): ?>
<section>
    <div class="section-head">
        <div>
            <span class="eyebrow"><?= e(__('why_us')) ?></span>
            <h2><?= e(__('why_us_title')) ?></h2>
        </div>
    </div>
    <div class="grid grid-features">
        <?php foreach ([['bolt', 'why_1'], ['shield', 'why_2'], ['cloud', 'why_3'], ['cpu', 'why_4'], ['puzzle', 'why_5'], ['chat', 'why_6']] as [$featureIcon, $featureKey]): ?>
            <div class="card feature">
                <span class="feature-icon"><?= icon($featureIcon) ?></span>
                <div>
                    <h3><?= e(__($featureKey . '_title')) ?></h3>
                    <p><?= e(__($featureKey . '_body')) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($show('categories') && $categories !== []): ?>
<section>
    <div class="section-head">
        <div>
            <span class="eyebrow"><?= e(__('categories')) ?></span>
            <h2><?= e(__('find_your_server')) ?></h2>
        </div>
    </div>
    <div class="grid grid-4">
        <?php foreach ($categories as $category): ?>
            <a class="card category-tile" href="<?= e(url('/catalog/' . $category['slug'])) ?>">
                <span class="feature-icon"><?= icon('server') ?></span>
                <h3 style="margin:10px 0 0"><?= e((string) $category['name']) ?></h3>
                <p><?= e((string) $category['description']) ?></p>
                <span class="more"><?= e(__('browse')) ?> <?= icon('arrow', 'icon icon-sm') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($show('steps') || $show('faq')): ?>
<section class="<?= $show('steps') && $show('faq') ? 'two-col' : '' ?>">
    <?php if ($show('steps')): ?>
    <div>
        <span class="eyebrow"><?= e(__('how_it_works')) ?></span>
        <h2><?= e(__('how_it_works_title')) ?></h2>
        <div class="steps" style="margin-top:18px">
            <div><h3><?= e(__('step1_title')) ?></h3><p class="muted"><?= e(__('step1_body')) ?></p></div>
            <div><h3><?= e(__('step2_title')) ?></h3><p class="muted"><?= e(__('step2_body')) ?></p></div>
            <div><h3><?= e(__('step3_title')) ?></h3><p class="muted"><?= e(__('step3_body')) ?></p></div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($show('faq')): ?>
    <div class="faq">
        <span class="eyebrow"><?= e(__('faq')) ?></span>
        <h2><?= e(__('faq_title')) ?></h2>
        <div style="margin-top:18px">
            <?php foreach ([1, 2, 3] as $faqIndex): ?>
                <details<?= $faqIndex === 1 ? ' open' : '' ?>>
                    <summary><?= e(__('faq_' . $faqIndex . '_q')) ?></summary>
                    <p><?= e(__('faq_' . $faqIndex . '_a')) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($show('cta')): ?>
<section>
    <div class="cta">
        <div>
            <h2><?= e(__('cta_title')) ?></h2>
            <p><?= e(__('cta_body')) ?></p>
        </div>
        <a class="btn btn-lg" href="<?= e(url('/catalog')) ?>"><?= e($heroCta) ?> <?= icon('arrow') ?></a>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>
