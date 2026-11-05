<?php
declare(strict_types=1);

/**
 * Minimal .env loader. Real environment variables always win,
 * so the same code works locally (.env file) and on Render (dashboard vars).
 */
final class Env
{
    /** @var array<string,string> */
    private static array $fileVars = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            // Strip optional surrounding quotes.
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$fileVars[trim($key)] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        $value = self::$fileVars[$key] ?? '';
        return $value !== '' ? $value : $default;
    }
}
