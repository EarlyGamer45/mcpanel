<?php

declare(strict_types=1);

use App\Http\AdminController;
use App\Http\ApiController;
use App\Http\AuthController;
use App\Http\CheckoutController;
use App\Http\ClientController;
use App\Http\InstallController;
use App\Http\PublicController;
use App\Http\WebhookController;

/** @var App\Core\Router $router */
$admin = '/' . trim((string) config('security.admin_path', 'admin'), '/');

// ----------------------------------------------------------------- public store
$router->get('/', [PublicController::class, 'home'])->name('home');
$router->get('/catalog', [PublicController::class, 'catalog'])->name('catalog');
$router->get('/catalog/{slug}', [PublicController::class, 'category'])->name('catalog.category');
$router->get('/server/{slug}', [PublicController::class, 'product'])->name('product');
$router->post('/configurator/quote', [PublicController::class, 'quote'])->stateless();
$router->get('/cart', [PublicController::class, 'cart'])->name('cart');
$router->post('/cart/add', [PublicController::class, 'addToCart'])->name('cart.add');
$router->post('/cart/remove', [PublicController::class, 'removeFromCart'])->name('cart.remove');
$router->get('/legal/{name}', [PublicController::class, 'legal'])->name('legal');
$router->get('/knowledge', [PublicController::class, 'knowledge'])->name('kb');
$router->get('/status', [PublicController::class, 'status'])->name('status');

// ----------------------------------------------------------------------- install
$router->get('/install', [InstallController::class, 'form'])->name('install');
$router->post('/install', [InstallController::class, 'install']);

// ------------------------------------------------------------------------- auth
$router->get('/login', [AuthController::class, 'loginForm'])->middleware('guest')->name('login');
$router->post('/login', [AuthController::class, 'login'])->middleware('guest');
$router->get('/login/2fa', [AuthController::class, 'twoFactorForm'])->name('login.2fa');
$router->post('/login/2fa', [AuthController::class, 'twoFactor']);
$router->post('/logout', [AuthController::class, 'logout'])->middleware('auth');
$router->get('/register', [AuthController::class, 'registerForm'])->middleware('guest')->name('register');
$router->post('/register', [AuthController::class, 'register'])->middleware('guest');
$router->get('/password/forgot', [AuthController::class, 'forgotForm'])->middleware('guest')->name('password.forgot');
$router->post('/password/forgot', [AuthController::class, 'sendReset'])->middleware('guest');
$router->get('/verify-email/{token}', [AuthController::class, 'verifyEmail'])->middleware('guest')->name('verify.email');
$router->get('/password/reset/{token}', [AuthController::class, 'resetForm'])->middleware('guest')->name('password.reset');
$router->post('/password/reset', [AuthController::class, 'reset'])->middleware('guest');

// --------------------------------------------------------------------- checkout
$router->get('/checkout', [CheckoutController::class, 'show'])->middleware('auth')->name('checkout');
$router->post('/checkout', [CheckoutController::class, 'create'])->middleware('auth')->name('checkout.create');
$router->get('/checkout/pay/{id}', [CheckoutController::class, 'pay'])->middleware('auth')->name('checkout.pay');
$router->post('/checkout/pay/{id}', [CheckoutController::class, 'startPayment'])->middleware('auth');
$router->get('/checkout/return/{id}', [CheckoutController::class, 'returnFromGateway'])->middleware('auth')->name('checkout.return');

// ----------------------------------------------------------------- client area
$router->get('/client', [ClientController::class, 'dashboard'])->middleware('auth')->name('client');
$router->get('/client/service/{id}', [ClientController::class, 'service'])->middleware('auth')->name('client.service');
$router->post('/client/service/{id}/power', [ClientController::class, 'power'])->middleware('auth');
$router->get('/client/service/{id}/console-token', [ClientController::class, 'consoleToken'])->middleware('auth');
$router->post('/client/service/{id}/cancel', [ClientController::class, 'cancel'])->middleware('auth');
$router->get('/client/invoices', [ClientController::class, 'invoices'])->middleware('auth')->name('client.invoices');
$router->get('/client/invoice/{id}/pdf', [ClientController::class, 'invoicePdf'])->middleware('auth');
$router->post('/client/invoice/{id}/pay', [ClientController::class, 'payInvoice'])->middleware('auth');
$router->get('/client/wallet', [ClientController::class, 'wallet'])->middleware('auth')->name('client.wallet');
$router->post('/client/wallet/topup', [ClientController::class, 'topUp'])->middleware('auth');
$router->get('/client/tickets', [ClientController::class, 'tickets'])->middleware('auth')->name('client.tickets');
$router->post('/client/tickets', [ClientController::class, 'openTicket'])->middleware('auth');
$router->get('/client/ticket/{id}', [ClientController::class, 'ticket'])->middleware('auth')->name('client.ticket');
$router->get('/client/attachment/{id}', [ClientController::class, 'downloadAttachment'])->middleware('auth');
$router->post('/client/ticket/{id}/reply', [ClientController::class, 'replyTicket'])->middleware('auth');
$router->get('/client/profile', [ClientController::class, 'profile'])->middleware('auth')->name('client.profile');
$router->post('/client/profile', [ClientController::class, 'updateProfile'])->middleware('auth');
$router->get('/client/security', [ClientController::class, 'security'])->middleware('auth')->name('client.security');
$router->post('/client/security/password', [ClientController::class, 'changePassword'])->middleware('auth');
$router->post('/client/security/email', [ClientController::class, 'changeEmail'])->middleware('auth');
$router->post('/client/security/2fa', [ClientController::class, 'enableTwoFactor'])->middleware('auth');
$router->post('/client/security/2fa/disable', [ClientController::class, 'disableTwoFactor'])->middleware('auth');
$router->get('/client/export', [ClientController::class, 'exportData'])->middleware('auth');
$router->post('/client/delete', [ClientController::class, 'deleteAccount'])->middleware('auth');

// ---------------------------------------------------------------------- admin
$router->get($admin, [AdminController::class, 'dashboard'])->middleware('admin')->name('admin');
$router->get($admin . '/reports', [AdminController::class, 'reports'])->middleware('admin')->name('admin.reports');
$router->get($admin . '/products', [AdminController::class, 'products'])->middleware('admin')->name('admin.products');
$router->post($admin . '/products/create', [AdminController::class, 'createProduct'])->middleware('admin');
$router->post($admin . '/catalog-settings', [AdminController::class, 'saveCatalogSettings'])->middleware('admin');
$router->post($admin . '/products/{id}/duplicate', [AdminController::class, 'duplicateProduct'])->middleware('admin');
$router->post($admin . '/products/{id}/delete', [AdminController::class, 'deleteProduct'])->middleware('admin');
$router->get($admin . '/products/{id}', [AdminController::class, 'product'])->middleware('admin')->name('admin.product');
$router->post($admin . '/products/{id}', [AdminController::class, 'saveProduct'])->middleware('admin');
$router->get($admin . '/addons', [AdminController::class, 'addons'])->middleware('admin')->name('admin.addons');
$router->post($admin . '/addons', [AdminController::class, 'saveAddon'])->middleware('admin');
$router->post($admin . '/addons/{id}', [AdminController::class, 'saveAddon'])->middleware('admin');
$router->post($admin . '/license', [AdminController::class, 'activateLicense'])->middleware('admin');
$router->post($admin . '/license/check', [AdminController::class, 'checkLicense'])->middleware('admin');
$router->get($admin . '/appearance', [AdminController::class, 'appearance'])->middleware('admin')->name('admin.appearance');
$router->post($admin . '/appearance', [AdminController::class, 'saveAppearance'])->middleware('admin');
$router->get($admin . '/services', [AdminController::class, 'services'])->middleware('admin')->name('admin.services');
$router->post($admin . '/services/{id}/action', [AdminController::class, 'serviceAction'])->middleware('admin');
$router->get($admin . '/orders', [AdminController::class, 'orders'])->middleware('admin')->name('admin.orders');
$router->get($admin . '/invoices', [AdminController::class, 'invoices'])->middleware('admin')->name('admin.invoices');
$router->get($admin . '/invoices/export', [AdminController::class, 'exportInvoices'])->middleware('admin')->name('admin.invoices.export');
$router->get($admin . '/invoice/{id}/pdf', [AdminController::class, 'invoicePdf'])->middleware('admin')->name('admin.invoice.pdf');
$router->post($admin . '/orders/delete', [AdminController::class, 'deleteOrders'])->middleware('admin');
$router->post($admin . '/orders/{id}/confirm', [AdminController::class, 'confirmBankTransfer'])->middleware('admin');
$router->get($admin . '/tickets', [AdminController::class, 'tickets'])->middleware('admin')->name('admin.tickets');
$router->get($admin . '/tickets/{id}', [AdminController::class, 'ticket'])->middleware('admin');
$router->post($admin . '/tickets/{id}/reply', [AdminController::class, 'replyTicket'])->middleware('admin');
$router->post($admin . '/tickets/{id}/close', [AdminController::class, 'closeTicket'])->middleware('admin');
$router->get($admin . '/attachment/{id}', [AdminController::class, 'downloadAttachment'])->middleware('admin');
$router->get($admin . '/content', [AdminController::class, 'content'])->middleware('admin')->name('admin.content');
$router->post($admin . '/content/articles', [AdminController::class, 'saveArticle'])->middleware('admin');
$router->get($admin . '/content/articles/{id}/edit', [AdminController::class, 'editArticle'])->middleware('admin');
$router->post($admin . '/content/articles/{id}', [AdminController::class, 'updateArticle'])->middleware('admin');
$router->post($admin . '/content/articles/{id}/toggle', [AdminController::class, 'toggleArticle'])->middleware('admin');
$router->post($admin . '/content/announcements', [AdminController::class, 'saveAnnouncement'])->middleware('admin');
$router->get($admin . '/content/announcements/{id}/edit', [AdminController::class, 'editAnnouncement'])->middleware('admin');
$router->post($admin . '/content/announcements/{id}', [AdminController::class, 'updateAnnouncement'])->middleware('admin');
$router->post($admin . '/content/announcements/{id}/toggle', [AdminController::class, 'toggleAnnouncement'])->middleware('admin');
$router->get($admin . '/coupons', [AdminController::class, 'coupons'])->middleware('admin')->name('admin.coupons');
$router->post($admin . '/coupons', [AdminController::class, 'saveCoupon'])->middleware('admin');
$router->post($admin . '/coupons/{id}/toggle', [AdminController::class, 'toggleCoupon'])->middleware('admin');
$router->post($admin . '/coupons/{id}/delete', [AdminController::class, 'deleteCoupon'])->middleware('admin');
$router->get($admin . '/clients', [AdminController::class, 'clients'])->middleware('admin')->name('admin.clients');
$router->get($admin . '/topups', [AdminController::class, 'topups'])->middleware('admin')->name('admin.topups');
$router->get($admin . '/nodes', [AdminController::class, 'nodes'])->middleware('admin')->name('admin.nodes');
$router->post($admin . '/nodes', [AdminController::class, 'saveNode'])->middleware('admin');
$router->post($admin . '/nodes/sync', [AdminController::class, 'syncNodes'])->middleware('admin');
$router->post($admin . '/nodes/{id}', [AdminController::class, 'saveNode'])->middleware('admin');
$router->post($admin . '/nodes/{id}/delete', [AdminController::class, 'deleteNode'])->middleware('admin');
$router->post($admin . '/locations', [AdminController::class, 'saveLocation'])->middleware('admin');
$router->post($admin . '/locations/{id}', [AdminController::class, 'saveLocation'])->middleware('admin');
$router->post($admin . '/locations/{id}/delete', [AdminController::class, 'deleteLocation'])->middleware('admin');
$router->post($admin . '/topups/{id}', [AdminController::class, 'decideTopup'])->middleware('admin');
$router->post($admin . '/clients/{id}/credit', [AdminController::class, 'creditWallet'])->middleware('admin');
$router->get($admin . '/clients/{id}', [AdminController::class, 'client'])->middleware('admin')->name('admin.client');
$router->post($admin . '/clients/{id}/update', [AdminController::class, 'updateClient'])->middleware('admin');
$router->post($admin . '/clients/{id}/status', [AdminController::class, 'clientStatus'])->middleware('admin');
$router->post($admin . '/clients/{id}/password', [AdminController::class, 'clientPassword'])->middleware('admin');
$router->post($admin . '/clients/{id}/delete', [AdminController::class, 'deleteClient'])->middleware('admin');
$router->get($admin . '/settings', [AdminController::class, 'settings'])->middleware('admin')->name('admin.settings');
$router->post($admin . '/settings', [AdminController::class, 'saveSettings'])->middleware('admin');
$router->get($admin . '/logs', [AdminController::class, 'logs'])->middleware('admin')->name('admin.logs');
$router->get($admin . '/diagnostics', [AdminController::class, 'diagnostics'])->middleware('admin')->name('admin.diagnostics');

// ------------------------------------------------------------------- webhooks
$router->post('/webhooks/stripe', [WebhookController::class, 'stripe'])->stateless();
$router->post('/webhooks/paypal', [WebhookController::class, 'paypal'])->stateless();

// ------------------------------------------------------------------ public API
$router->get('/api/v1/health', [ApiController::class, 'health'])->stateless();
$router->get('/api/v1/catalog', [ApiController::class, 'catalog'])->stateless();
