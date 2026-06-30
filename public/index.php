<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use BB\Auth\AuthService;
use BB\Auth\UnauthorizedException;
use BB\Database\DB;
use BB\Http\Request;
use BB\Http\Response;
use BB\Repository\CartRepository;
use BB\Repository\OrderRepository;
use BB\Scraper\ProductScraper;

$request = new Request();
$path = $request->path();
// Normalize path: strip /public prefix when doc root is project root
if (str_starts_with($path, '/public')) {
    $path = substr($path, strlen('/public')) ?: '/';
}
$method = $request->method();
$auth = new AuthService();

try {
    if ($method === 'POST' && $path === '/api/auth/register') {
        Response::json($auth->register(
            (string) $request->input('name', 'Customer'),
            (string) $request->input('email'),
            (string) $request->input('password')
        ), 201);
        return;
    }

    if ($method === 'POST' && $path === '/api/auth/login') {
        $result = $auth->login((string) $request->input('email'), (string) $request->input('password'));
        if (!$result) {
            Response::json(['error' => 'Invalid credentials'], 422);
            return;
        }
        Response::json($result);
        return;
    }

    if ($method === 'GET' && $path === '/api/me') {
        $user = $auth->requireUser($request->bearerToken());
        unset($user['password_hash']);
        Response::json(['user' => $user]);
        return;
    }

    if ($method === 'POST' && ($path === '/scrape-product' || $path === '/api/scrape-product')) {
        $raw = file_get_contents('php://input');
        BB\Support\Logger::info('Scrape request', ['raw' => $raw, 'input_url' => $request->input('url')]);
        $product = (new ProductScraper())->scrape((string) $request->input('url'));
        Response::json(['product' => $product]);
        return;
    }

    if ($path === '/api/cart') {
        $user = $auth->requireUser($request->bearerToken());
        $repo = new CartRepository();
        if ($method === 'GET') {
            Response::json(['cart' => $repo->activeCart((int) $user['id'])]);
            return;
        }
        if ($method === 'POST') {
            Response::json(['cart' => $repo->addItem((int) $user['id'], $request->json())], 201);
            return;
        }
    }

    if (preg_match('#^/api/cart/items/(\d+)$#', $path, $matches)) {
        $user = $auth->requireUser($request->bearerToken());
        $repo = new CartRepository();
        if ($method === 'PATCH') {
            Response::json(['cart' => $repo->updateQuantity((int) $user['id'], (int) $matches[1], (int) $request->input('quantity', 1))]);
            return;
        }
        if ($method === 'DELETE') {
            Response::json(['cart' => $repo->removeItem((int) $user['id'], (int) $matches[1])]);
            return;
        }
    }

    if ($path === '/api/orders') {
        $user = $auth->requireUser($request->bearerToken());
        $repo = new OrderRepository();
        if ($method === 'GET') {
            Response::json(['orders' => $repo->listForUser((int) $user['id'])]);
            return;
        }
        if ($method === 'POST') {
            Response::json(['order' => $repo->createFromCart((int) $user['id'], (string) $request->input('payment_method', 'prepay'))], 201);
            return;
        }
    }

    if ($method === 'POST' && $path === '/api/quote-requests') {
        $user = $auth->userFromToken($request->bearerToken());
        $data = $request->json();
        $stmt = DB::pdo()->prepare(
            'INSERT INTO quote_requests (user_id, product_url, product_name, product_image, currency, unit_price, quantity, weight_kg, estimated_total_npr)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'] ?? null,
            $data['product_url'] ?? null,
            $data['product_name'] ?? null,
            $data['product_image'] ?? null,
            $data['currency'] ?? null,
            $data['unit_price'] ?? null,
            (int) ($data['quantity'] ?? 1),
            $data['weight_kg'] ?? null,
            $data['estimated_total_npr'] ?? null,
        ]);
        Response::json(['id' => (int) DB::pdo()->lastInsertId()], 201);
        return;
    }

    if (str_starts_with($path, '/admin')) {
        require BASE_PATH . '/src/Admin/routes.php';
        return;
    }

    Response::json(['error' => 'Not found'], 404);
} catch (UnauthorizedException) {
    Response::json(['error' => 'Unauthorized'], 401);
} catch (InvalidArgumentException $exception) {
    Response::json(['error' => $exception->getMessage()], 422);
}
