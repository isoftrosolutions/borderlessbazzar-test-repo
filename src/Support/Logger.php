<?php

declare(strict_types=1);

namespace BB\Support;

final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
        self::writeTo('error.log', 'ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        self::writeTo('app.log', $level, $message, $context);
    }

    private static function writeTo(string $file, string $level, string $message, array $context): void
    {
        $line = json_encode([
            'time' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES);

        file_put_contents(BASE_PATH . '/storage/logs/' . $file, $line . PHP_EOL, FILE_APPEND);
    }
}
