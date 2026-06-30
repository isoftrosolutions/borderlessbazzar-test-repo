<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use BB\Database\DB;
use BB\Support\Env;

$pdo = DB::pdo();
$schema = file_get_contents(BASE_PATH . '/database/schema.sql');

if (!$schema) {
    throw new RuntimeException('Missing schema.sql');
}

$pdo->exec($schema);

$adminEmail = Env::get('ADMIN_EMAIL');
$adminPassword = Env::get('ADMIN_PASSWORD');

if ($adminEmail && $adminPassword) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$adminEmail]);

    if (!$stmt->fetch()) {
        $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $insert->execute([
            'Admin',
            $adminEmail,
            password_hash($adminPassword, PASSWORD_DEFAULT),
            'admin',
        ]);
    }
}

echo "MySQL schema migrated.\n";
