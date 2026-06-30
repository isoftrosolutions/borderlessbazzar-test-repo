<?php

declare(strict_types=1);

namespace BB\Repository;

use BB\Database\DB;

final class OrderRepository
{
    public function createFromCart(int $userId, string $paymentMethod = 'prepay'): array
    {
        $cartRepo = new CartRepository();
        $cart = $cartRepo->activeCart($userId);
        if (!$cart['items']) {
            throw new \InvalidArgumentException('Cart is empty');
        }

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $orderNumber = 'BB-' . substr((string) time(), -7) . random_int(10, 99);
            $stmt = $pdo->prepare(
                'INSERT INTO orders (user_id, order_number, subtotal_npr, shipping_npr, total_npr, payment_method, status)
                 VALUES (?, ?, ?, ?, ?, ?, "confirmed")'
            );
            $stmt->execute([$userId, $orderNumber, $cart['subtotal_npr'], $cart['shipping_npr'], $cart['total_npr'], $paymentMethod]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_url, name, image, source, unit_price_npr, quantity, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($cart['items'] as $item) {
                $itemStmt->execute([
                    $orderId,
                    $item['product_url'],
                    $item['name'],
                    $item['image'],
                    $item['source'],
                    $item['unit_price_npr'],
                    $item['quantity'],
                    $item['metadata'],
                ]);
            }

            $pdo->prepare('UPDATE carts SET status = "ordered" WHERE id = ?')->execute([$cart['id']]);
            $pdo->commit();

            return $this->find($orderId);
        } catch (\Throwable $throwable) {
            $pdo->rollBack();
            throw $throwable;
        }
    }

    public function listForUser(int $userId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function find(int $orderId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new \InvalidArgumentException('Order not found');
        }
        $items = DB::pdo()->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        $order['items'] = $items->fetchAll();
        return $order;
    }
}
