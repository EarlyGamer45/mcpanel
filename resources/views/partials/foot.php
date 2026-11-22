<?php
$footTheme = \App\Domain\Theme::get();
$footBrand = (string) setting('brand_name', config('app.name'));
?>
<?php if (!empty($shell)): ?>
        </main>
        <footer class="shell-foot">
            <span>© <?= gmdate('Y') ?> <?= e(setting('company_name', $footBrand)) ?></span>
            <span><?= e(__('footer_version', ['version' => \App\Core\App::version()])) ?></span>
        </footer>
    </div>
</div>
<?php else: ?>
</main>
<footer class="site">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a class="brand" href="<?= e(url('/')) ?>">
                    <?php if ($footTheme['logo_data'] !== ''): ?>
                        <img class="brand-logo" src="<?= e($footTheme['logo_data']) ?>" alt="<?= e($footBrand) ?>">
                    <?php else: ?>
                        <span class="brand-mark" aria-hidden="true"><span></span></span>
                    <?php endif; ?>
                    <?php if ($footTheme['show_brand_name'] || $footTheme['logo_data'] === ''): ?><span class="brand-name"><?= e($footBrand) ?></span><?php endif; ?>
                </a>
                <p class="muted"><?= e($footTheme['footer_text'] !== '' ? $footTheme['footer_text'] : __('footer_tagline')) ?></p>
            </div>
            <div>
                <p class="footer-title"><?= e(__('footer_product')) ?></p>
                <a href="<?= e(url('/catalog')) ?>"><?= e(__('catalog')) ?></a>
                <a href="<?= e(url('/knowledge')) ?>"><?= e(__('knowledge_base')) ?></a>
                <a href="<?= e(url('/status')) ?>"><?= e(__('status_page')) ?></a>
                <a href="<?= e(url('/api/v1/health')) ?>">API</a>
            </div>
            <div>
                <p class="footer-title"><?= e(__('footer_legal')) ?></p>
                <a href="<?= e(url('/legal/terms')) ?>"><?= e(__('terms')) ?></a>
                <a href="<?= e(url('/legal/privacy')) ?>"><?= e(__('privacy')) ?></a>
                <a href="<?= e(url('/legal/aup')) ?>"><?= e(__('aup')) ?></a>
                <a href="<?= e(url('/legal/refunds')) ?>"><?= e(__('refunds')) ?></a>
                <a href="<?= e(url('/legal/sla')) ?>"><?= e(__('sla')) ?></a>
            </div>
        </div>
        <div class="footer-bottom">
            <p>
                © <?= gmdate('Y') ?> <?= e(setting('company_name', $footBrand)) ?> ·
                <?= e(__('vat')) ?> <?= e(setting('company_vat', '—')) ?>
            </p>
            <p><?= e(__('not_affiliated')) ?></p>
        </div>
    </div>
</footer>
<?php endif; ?>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
