<?php
declare(strict_types=1);

/**
 * Shared bootstrap for web endpoints, CLI scripts and tests.
 * - Loads classes from src/ (one class per file, no Composer needed).
 * - Loads a local .env file when present (production uses real env vars).
 */

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load(dirname(__DIR__) . '/.env');
