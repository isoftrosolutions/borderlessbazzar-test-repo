<?php

declare(strict_types=1);

namespace BB\Repository;

use BB\Database\DB;
use PDO;

final class CartRepository
{
    public function activeCart(int $userId): array
    {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT * FROM carts WHERE user_id = ? AND status = "active" ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cart) {
            $pdo->prepare('INSERT INTO carts (user_id) VALUES (?)')->execute([$userId]);
            $cart = ['id' => (int) $pdo->lastInsertId(), 'user_id' => $userId, 'status' => 'active'];
        }

        $cart['items'] = $this->items((int) $cart['id']);
        $cart['subtotal_npr'] = array_sum(array_map(fn ($item) => $item['unit_price_npr'] * $item['quantity'], $cart['items']));
        $cart['shipping_npr'] = count($cart['items']) ? 3200 : 0;
        $cart['total_npr'] = $cart['subtotal_npr'] + $cart['shipping_npr'];

        return $cart;
    }

    public function addItem(int $userId, array $payload): array
    {
        $cart = $this->activeCart($userId);
        $stmt = DB::pdo()->prepare(
            'INSERT INTO cart_items (cart_id, product_url, name, image, source, unit_price_npr, quantity, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $cart['id'],
            $payload['product_url'] ?? null,
            $payload['name'] ?? 'Product',
            $payload['image'] ?? null,
            $payload['source'] ?? null,
            max(0, (int) ($payload['unit_price_npr'] ?? 0)),
            max(1, (int) ($payload['quantity'] ?? 1)),
            json_encode($payload['metadata'] ?? [], JSON_UNESCAPED_SLASHES),
        ]);

        return $this->activeCart($userId);
    }

    public function updateQuantity(int $userId, int $itemId, int $quantity): array
    {
        $cart = $this->activeCart($userId);
        DB::pdo()->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND cart_id = ?')
            ->execute([max(1, $quantity), $itemId, $cart['id']]);
        return $this->activeCart($userId);
    }

    public function removeItem(int $userId, int $itemId): array
    {
        $cart = $this->activeCart($userId);
        DB::pdo()->prepare('DELETE FROM cart_items WHERE id = ? AND cart_id = ?')->execute([$itemId, $cart['id']]);
        return $this->activeCart($userId);
    }

    private function items(int $cartId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM cart_items WHERE cart_id = ? ORDER BY id DESC');
        $stmt->execute([$cartId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
