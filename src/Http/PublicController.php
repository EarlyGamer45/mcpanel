<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Cart;
use App\Domain\Catalog;
use App\Domain\PriceCalculator;
use App\Domain\PlanRecommender;

final class PublicController
{
    public function home(Request $request, array $params): Response
    {
        $products = Catalog::products();
        $announcements = App::db()->select(
            'SELECT * FROM announcements WHERE visible = 1 AND (published_at IS NULL OR published_at <= :now) ORDER BY id DESC LIMIT 3',
            ['now' => gmdate('Y-m-d H:i:s')]
        );

        return Response::view('home', [
            'products' => array_slice($products, 0, 6),
            'categories' => Catalog::categories(),
            'locations' => Catalog::locations(),
            'announcements' => $announcements,
            'cartCount' => Cart::count(),
        ]);
    }

    public function catalog(Request $request, array $params): Response
    {
        return Response::view('catalog', [
            'products' => Catalog::products(),
            'categories' => Catalog::categoriesWithProducts(),
            'cartCount' => Cart::count(),
        ]);
    }

    public function category(Request $request, array $params): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        $category = App::db()->selectOne('SELECT * FROM categories WHERE slug = :slug AND visible = 1', ['slug' => $slug]);
        if ($category === null) {
            throw new HttpException(404);
        }

        return Response::view('catalog', [
            'products' => Catalog::products((int) $category['id']),
            'categories' => Catalog::categoriesWithProducts(),
            'currentCategory' => $category,
            'cartCount' => Cart::count(),
        ]);
    }

    public function product(Request $request, array $params): Response
    {
        $product = Catalog::product((string) ($params['slug'] ?? ''));
        if ($product === null) {
            throw new HttpException(404);
        }
        $options = $product['options'];
        $selection = [
            'ram_mb' => (int) ($options['ram']['min_value'] ?? $product['min_ram_mb']),
            'cpu_percent' => (int) ($options['cpu']['min_value'] ?? 100),
            'disk_mb' => (int) ($options['disk']['min_value'] ?? 5120),
            'version' => $product['versions'][0] ?? '1.21.8',
            'software' => Catalog::softwareFor($product),
            'location_id' => null,
        ];
        $isFixed = ($product['plan_type'] ?? 'custom') === 'fixed';
        if (!$isFixed) {
            // Configurator: resources are chosen with the sliders, so only service packs are offered.
            $product['addons'] = array_values(array_filter($product['addons'], static fn (array $a): bool => PriceCalculator::addonEffect($a) === null));
        }
        $allowed = array_map(static fn (array $a): int => (int) $a['id'], $product['addons']);
        $preselected = array_values(array_intersect(array_map('intval', $request->array('addons')), $allowed));
        $quote = PriceCalculator::quote($product, $selection, $preselected, 'monthly');
        $quote['addon_ids'] = $preselected;

        return Response::view('product', [
            'product' => $product,
            'quote' => $quote,
            'selection' => $selection,
            'locations' => Catalog::locations(),
            'cycles' => \App\Core\Money::cycles(),
            'configurator' => Catalog::firstConfigurator(),
            'cartCount' => Cart::count(),
        ]);
    }

    /** JSON endpoint used by the configurator to refresh the price client-side. */
    public function quote(Request $request, array $params): Response
    {
        $product = Catalog::productById($request->int('product_id'));
        if ($product === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $selection = $this->selectionFrom($request, $product);
        $addons = array_map('intval', $request->array('addons'));
        $quote = PriceCalculator::quote($product, $selection, $addons, (string) $request->string('cycle', 'monthly'), $request->string('coupon'));
        $lines = [];
        foreach ($quote['resources'] as $line) {
            $lines[] = ['label' => $line['label'], 'amount' => money((int) $line['amount_cents'])];
        }
        foreach ($quote['addons'] as $line) {
            $lines[] = ['label' => $line['name'], 'amount' => money((int) $line['amount_cents'])];
        }

        return Response::json([
            'total' => money((int) $quote['total_cents']),
            'total_cents' => (int) $quote['total_cents'],
            'base' => money((int) $quote['base_cents']),
            'discount' => money((int) $quote['discount_cents']),
            'lines' => $lines,
            'cycle_label' => __((string) $quote['cycle']),
        ]);
    }

    public function cart(Request $request, array $params): Response
    {
        $items = Cart::items();

        return Response::view('cart', [
            'items' => $items,
            'total' => Cart::total($items),
            'cartCount' => count($items),
        ]);
    }

    public function addToCart(Request $request, array $params): Response
    {
        $product = Catalog::productById($request->int('product_id'));
        if ($product === null) {
            throw new HttpException(404);
        }
        if (!\App\Domain\LicenseClient::allowsSales()) {
            flash('error', __('license_sales_blocked'));

            return Response::redirect(url('/server/' . $product['slug']));
        }
        $selection = $this->selectionFrom($request, $product);
        $addons = array_map('intval', $request->array('addons'));
        $quote = PriceCalculator::quote($product, $selection, $addons, (string) $request->string('cycle', 'monthly'), $request->string('coupon'));
        Cart::add((int) $product['id'], $quote, $selection['location_id']);
        flash('success', __('cart_added'));

        return Response::redirect(url('/cart'));
    }

    public function removeFromCart(Request $request, array $params): Response
    {
        Cart::remove($request->int('item_id'));

        return Response::redirect(url('/cart'));
    }

    public function legal(Request $request, array $params): Response
    {
        $name = (string) ($params['name'] ?? 'terms');
        $document = App::db()->selectOne('SELECT * FROM legal_documents WHERE name = :name', ['name' => $name]);
        if ($document === null) {
            throw new HttpException(404);
        }

        return Response::view('legal', ['document' => $document, 'name' => $name]);
    }

    public function knowledge(Request $request, array $params): Response
    {
        $term = $request->string('q');
        $articles = $term !== ''
            ? App::db()->select('SELECT * FROM kb_articles WHERE published = 1 AND (title LIKE :term OR body LIKE :term) ORDER BY id DESC LIMIT 50', ['term' => '%' . $term . '%'])
            : App::db()->select('SELECT * FROM kb_articles WHERE published = 1 ORDER BY category ASC, id DESC LIMIT 50');

        return Response::view('knowledge', ['articles' => $articles, 'term' => $term]);
    }

    public function status(Request $request, array $params): Response
    {
        $nodes = App::db()->select(
            'SELECT n.name, n.maintenance, l.name AS location, l.ddos_note FROM nodes n JOIN locations l ON l.id = n.location_id WHERE n.visible = 1 ORDER BY l.sort ASC, n.id ASC'
        );
        $incidents = App::db()->select('SELECT * FROM announcements WHERE visible = 1 AND level IN (:warning, :danger) ORDER BY id DESC LIMIT 5', ['warning' => 'warning', 'danger' => 'danger']);

        return Response::view('status', ['nodes' => $nodes, 'incidents' => $incidents]);
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function selectionFrom(Request $request, array $product): array
    {
        $recommend = null;
        if ($request->string('recommend') !== '') {
            $recommend = PlanRecommender::suggest($request->int('players', 5), $request->string('server_type', 'vanilla'), []);
        }

        return [
            'ram_mb' => $request->int('ram_mb', $recommend['ram_mb'] ?? (int) ($product['min_ram_mb'] ?? 1024)),
            'cpu_percent' => $request->int('cpu_percent', 100),
            'disk_mb' => $request->int('disk_mb', 5120),
            'version' => $request->string('version', (string) (($product['versions'] ?? ['1.21.8'])[0] ?? '1.21.8')),
            'software' => $request->string('software', Catalog::softwareFor($product)),
            'location_id' => $request->int('location_id') ?: null,
        ];
    }
}
