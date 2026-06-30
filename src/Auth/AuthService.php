<?php

declare(strict_types=1);

namespace BB\Auth;

use BB\Database\DB;
use DateInterval;
use DateTimeImmutable;
use PDO;

final class AuthService
{
    public function register(string $name, string $email, string $password): array
    {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);

        return $this->issueToken((int) $pdo->lastInsertId());
    }

    public function login(string $email, string $password): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower($email)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        return $this->issueToken((int) $user['id']);
    }

    public function userFromToken(?string $token): ?array
    {
        if (!$token) {
            return null;
        }

        $hash = hash('sha256', $token);
        $stmt = DB::pdo()->prepare(
            'SELECT users.* FROM access_tokens JOIN users ON users.id = access_tokens.user_id WHERE token_hash = ? AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$hash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function requireUser(?string $token): array
    {
        $user = $this->userFromToken($token);
        if (!$user) {
            throw new UnauthorizedException('Unauthorized');
        }
        return $user;
    }

    private function issueToken(int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable())->add(new DateInterval('P30D'));

        $stmt = DB::pdo()->prepare('INSERT INTO access_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$userId, hash('sha256', $token), $expires->format('Y-m-d H:i:s')]);

        $userStmt = DB::pdo()->prepare('SELECT id, name, email, role, phone, created_at FROM users WHERE id = ?');
        $userStmt->execute([$userId]);

        return [
            'token' => $token,
            'expires_at' => $expires->format(DATE_ATOM),
            'user' => $userStmt->fetch(PDO::FETCH_ASSOC),
        ];
    }
}
