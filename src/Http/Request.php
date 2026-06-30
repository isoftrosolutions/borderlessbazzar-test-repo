<?php

declare(strict_types=1);

namespace BB\Http;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return '/' . trim($uri, '/');
    }

    public function json(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $data = $this->json();
        return $data[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
