<?php

declare(strict_types=1);

namespace BB\Http;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    public static function redirect(string $to): void
    {
        header('Location: ' . $to, true, 302);
    }
}
