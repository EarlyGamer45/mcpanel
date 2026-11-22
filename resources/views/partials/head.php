<?php
$theme = \App\Domain\Theme::get();
$brand = (string) setting('brand_name', config('app.name'));
$path = current_path();
$adminBase = '/' . trim((string) config('security.admin_path', 'admin'), '/');
$logged = $currentUser !== null;
$admin = $logged && \App\Core\Auth::isAdmin();
$adminArea = $admin && ($path === $adminBase || str_starts_with($path, $adminBase . '/'));
$clientArea = $logged && !$adminArea && ($path === '/client' || str_starts_with($path, '/client/'));
$shell = $adminArea || $clientArea;
$title = isset($pageTitle) ? $pageTitle . ' · ' . $brand : $brand;
$cartCount = (int) ($cartCount ?? 0);
$licenseBanner = $adminArea ? \App\Domain\LicenseClient::banner() : null;
$favicon = $theme['favicon_data'] !== ''
    ? $theme['favicon_data']
    : "data:image/svg+xml," . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='" . $theme['brand_color'] . "'/><rect x='9' y='9' width='14' height='14' rx='3' fill='#ffffff'/></svg>");

$isActive = static function (string $href, string $root) use ($path): bool {
    return $path === $href || ($href !== $root && str_starts_with($path, $href . '/')) || ($href !== $root && $path === $href);
};

$navGroups = [];
$navBadges = [];
if ($adminArea) {
    try {
        $db = \App\Core\App::db();
        $navBadges[$adminBase . '/tickets'] = (int) $db->value('SELECT COUNT(*) FROM tickets WHERE status <> :closed', ['closed' => 'closed']);
        $navBadges[$adminBase . '/orders'] = (int) $db->value('SELECT COUNT(*) FROM orders WHERE status IN (:p, :r)', ['p' => 'pending_payment', 'r' => 'review']);
        $navBadges[$adminBase . '/topups'] = (int) $db->value('SELECT COUNT(*) FROM wallet_topups WHERE status = :p', ['p' => 'pending']);
    } catch (\Throwable) {
        $navBadges = [];
    }
    $navGroups = [
        __('nav_overview') => [
            [$adminBase, __('dashboard'), 'home'],
            [$adminBase . '/reports', __('reports'), 'chart'],
        ],
        __('nav_sales') => [
            [$adminBase . '/orders', __('orders'), 'cart'],
            [$adminBase . '/invoices', __('invoices'), 'receipt'],
            [$adminBase . '/topups', __('wallet_topups'), 'wallet'],
            [$adminBase . '/clients', __('clients'), 'users'],
            [$adminBase . '/coupons', __('coupons'), 'tag'],
        ],
        __('nav_catalog') => [
            [$adminBase . '/products', __('products'), 'box'],
            [$adminBase . '/addons', __('expansion_packs'), 'puzzle'],
            [$adminBase . '/content', __('content'), 'file'],
        ],
        __('nav_operations') => [
            [$adminBase . '/services', __('services'), 'server'],
            [$adminBase . '/nodes', __('nodes'), 'cpu'],
            [$adminBase . '/tickets', __('tickets'), 'chat'],
            [$adminBase . '/logs', __('logs'), 'list'],
            [$adminBase . '/diagnostics', __('diagnostics'), 'pulse'],
        ],
        __('nav_system') => [
            [$adminBase . '/appearance', __('appearance'), 'palette'],
            [$adminBase . '/settings', __('settings'), 'settings'],
        ],
    ];
    $navRoot = $adminBase;
} elseif ($clientArea) {
    $navGroups = [
        __('client_area') => [
            ['/client', __('dashboard'), 'home'],
            ['/client/invoices', __('invoices'), 'receipt'],
            ['/client/wallet', __('wallet'), 'wallet'],
            ['/client/tickets', __('tickets'), 'chat'],
        ],
        __('account') => [
            ['/client/profile', __('profile'), 'user'],
            ['/client/security', __('security'), 'shield'],
        ],
    ];
    $navRoot = '/client';
}
?>
<!doctype html>
<html lang="<?= e($locale ?? 'en') ?>" data-theme="<?= e($theme['theme_mode'] === 'light' ? 'light' : 'dark') ?>" data-theme-default="<?= e($theme['theme_mode']) ?>" class="bg-<?= e($theme['background_style']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e(setting('meta_description', __('meta_description'))) ?>">
    <meta name="theme-color" content="<?= e($theme['brand_color']) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e(setting('meta_description', __('meta_description'))) ?>">
    <script>
        (function () {
            var root = document.documentElement, mode = root.getAttribute('data-theme-default'), saved = null;
            try { saved = localStorage.getItem('theme'); } catch (e) {}
            if (<?= $theme['theme_toggle'] ? 'true' : 'false' ?> && (saved === 'dark' || saved === 'light')) { mode = saved; }
            if (mode === 'auto') { mode = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'; }
            root.setAttribute('data-theme', mode);
        })();
    </script>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <style><?= \App\Domain\Theme::cssVariables($theme) ?></style>
    <?php if ($theme['custom_css'] !== ''): ?><style id="custom-css"><?= $theme['custom_css'] ?></style><?php endif; ?>
    <link rel="icon" href="<?= e($favicon) ?>">
</head>
<body class="<?= $shell ? 'has-shell' : 'is-public' ?>">
<a class="skip" href="#main"><?= e(__('skip_to_content')) ?></a>
<?php if ($theme['topbar_text'] !== '' && !$shell): ?>
    <div class="announce-bar">
        <div class="container">
            <?= icon('megaphone') ?>
            <?php if ($theme['topbar_link'] !== ''): ?>
                <a href="<?= e($theme['topbar_link']) ?>"><?= e($theme['topbar_text']) ?> <?= icon('arrow', 'icon icon-sm') ?></a>
            <?php else: ?>
                <span><?= e($theme['topbar_text']) ?></span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
$brandMarkup = static function () use ($theme, $brand): string {
    $out = '';
    if ($theme['logo_data'] !== '') {
        $out .= '<img class="brand-logo" src="' . e($theme['logo_data']) . '" alt="' . e($brand) . '">';
    } else {
        $out .= '<span class="brand-mark" aria-hidden="true"><span></span></span>';
    }
    if ($theme['show_brand_name'] || $theme['logo_data'] === '') {
        $out .= '<span class="brand-name">' . e($brand) . '</span>';
    }

    return $out;
};
$themeToggle = static function () use ($theme): string {
    if (!$theme['theme_toggle']) {
        return '';
    }

    return '<button class="icon-btn theme-toggle" type="button" data-theme-toggle aria-label="' . e(__('toggle_theme')) . '" title="' . e(__('toggle_theme')) . '">'
        . icon('sun', 'icon icon-sun') . icon('moon', 'icon icon-moon') . '</button>';
};
?>

<?php if ($shell): ?>
<div class="shell">
    <aside class="sidebar" id="sidebar" aria-label="<?= e($adminArea ? __('admin_panel') : __('client_area')) ?>">
        <div class="sidebar-head">
            <a class="brand" href="<?= e(url($adminArea ? $adminBase : '/client')) ?>"><?= $brandMarkup() ?></a>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($navGroups as $groupLabel => $links): ?>
                <div class="nav-group">
                    <p class="nav-group-label"><?= e((string) $groupLabel) ?></p>
                    <?php foreach ($links as [$href, $label, $iconName]): ?>
                        <?php $active = $isActive($href, $navRoot); $badge = (int) ($navBadges[$href] ?? 0); ?>
                        <a href="<?= e(url($href)) ?>" class="nav-link<?= $active ? ' active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                            <?= icon($iconName) ?><span><?= e($label) ?></span>
                            <?php if ($badge > 0): ?><span class="nav-count"><?= $badge ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <?php if ($adminArea): ?>
                <a class="nav-link" href="<?= e(url('/client')) ?>"><?= icon('user') ?><span><?= e(__('client_area')) ?></span></a>
            <?php elseif ($admin): ?>
                <a class="nav-link" href="<?= e(url($adminBase)) ?>"><?= icon('shield') ?><span><?= e(__('admin_panel')) ?></span></a>
            <?php endif; ?>
            <a class="nav-link" href="<?= e(url('/')) ?>"><?= icon('external') ?><span><?= e(__('view_site')) ?></span></a>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <div class="shell-main">
        <header class="shell-top">
            <button class="icon-btn menu-btn" type="button" data-sidebar-open aria-controls="sidebar" aria-label="<?= e(__('main_menu')) ?>"><?= icon('menu') ?></button>
            <p class="shell-title"><?= e($pageTitle ?? $brand) ?></p>
            <div class="shell-actions">
                <?= $themeToggle() ?>
                <?php if (!$adminArea): ?>
                    <a class="icon-btn" href="<?= e(url('/cart')) ?>" aria-label="<?= e(__('cart')) ?>"><?= icon('cart') ?><?php if ($cartCount > 0): ?><span class="dot-count"><?= $cartCount ?></span><?php endif; ?></a>
                <?php endif; ?>
                <a class="user-chip" href="<?= e(url('/client/profile')) ?>">
                    <span class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($currentUser['username'] ?? '?'), 0, 1))) ?></span>
                    <span class="user-name"><?= e((string) ($currentUser['username'] ?? '')) ?></span>
                </a>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="icon-btn" type="submit" aria-label="<?= e(__('logout')) ?>" title="<?= e(__('logout')) ?>"><?= icon('logout') ?></button>
                </form>
            </div>
        </header>
        <main id="main" class="shell-content">
<?php else: ?>
<header class="topbar">
    <div class="container topbar-inner">
        <a class="brand" href="<?= e(url('/')) ?>"><?= $brandMarkup() ?></a>
        <button class="icon-btn menu-btn" type="button" data-nav-toggle aria-controls="site-nav" aria-expanded="false" aria-label="<?= e(__('main_menu')) ?>"><?= icon('menu') ?></button>
        <nav class="nav" id="site-nav" aria-label="<?= e(__('main_menu')) ?>">
            <div class="nav-links">
                <a href="<?= e(url('/catalog')) ?>" class="<?= is_active('/catalog') || is_active('/server') ? 'active' : '' ?>"><?= e(__('catalog')) ?></a>
                <a href="<?= e(url('/knowledge')) ?>" class="<?= is_active('/knowledge') ? 'active' : '' ?>"><?= e(__('knowledge_base')) ?></a>
                <a href="<?= e(url('/status')) ?>" class="<?= is_active('/status') ? 'active' : '' ?>"><?= e(__('status_page')) ?></a>
            </div>
            <div class="nav-actions">
                <?= $themeToggle() ?>
                <a class="icon-btn" href="<?= e(url('/cart')) ?>" aria-label="<?= e(__('cart')) ?>"><?= icon('cart') ?><?php if ($cartCount > 0): ?><span class="dot-count"><?= $cartCount ?></span><?php endif; ?></a>
                <?php if ($logged): ?>
                    <?php if ($admin): ?><a class="btn btn-ghost btn-sm" href="<?= e(url($adminBase)) ?>"><?= e(__('admin_panel')) ?></a><?php endif; ?>
                    <a class="btn btn-sm" href="<?= e(url('/client')) ?>"><?= icon('user', 'icon icon-sm') ?><?= e(__('client_area')) ?></a>
                <?php else: ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/login')) ?>"><?= e(__('login')) ?></a>
                    <a class="btn btn-sm" href="<?= e(url('/register')) ?>"><?= e(__('create_account')) ?></a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>
<main id="main" class="container site-main">
<?php endif; ?>
    <?php if ($licenseBanner !== null): ?>
        <div class="alert <?= $licenseBanner['level'] === 'danger' ? 'alert-danger' : '' ?>" role="status">
            <?= e($licenseBanner['message']) ?>
            <a href="<?= e(url($adminBase . '/settings')) ?>"><?= e(__('manage_license')) ?></a>
        </div>
    <?php endif; ?>
    <?php if (!empty($flashes)): ?>
        <div class="flashes" role="status" aria-live="polite">
            <?php foreach ($flashes as $flashType => $flashMessages): ?>
                <?php foreach ($flashMessages as $flashMessage): ?>
                    <div class="flash flash-<?= e((string) $flashType) ?>"><?= icon($flashType === 'success' ? 'check' : 'bolt', 'icon icon-sm') ?><span><?= e((string) $flashMessage) ?></span></div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
