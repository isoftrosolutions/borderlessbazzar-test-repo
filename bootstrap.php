<?php

declare(strict_types=1);

define('BASE_PATH', __DIR__);

spl_autoload_register(function (string $class): void {
    $prefix = 'BB\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = BASE_PATH . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

    if (is_file($path)) {
        require $path;
    }
});

BB\Support\Env::load(BASE_PATH . '/.env');

require BASE_PATH . '/config.php';

set_exception_handler(function (Throwable $exception): void {
    BB\Support\Logger::error($exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server error']);
});
