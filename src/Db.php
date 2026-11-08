<?php
declare(strict_types=1);

/**
 * Creates a PDO connection to Postgres from a DATABASE_URL
 * (the format Neon gives you: postgresql://user:pass@host/db?sslmode=require).
 */
final class Db
{
    public static function connect(): PDO
    {
        $url = Env::get('DATABASE_URL');
        if ($url === null) {
            throw new RuntimeException('DATABASE_URL is not set');
        }
        [$dsn, $user, $pass] = self::parseUrl($url);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    }

    /**
     * @return array{0:string,1:string,2:string} [dsn, user, password]
     */
    public static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        if ($parts === false || !in_array($scheme, ['postgres', 'postgresql'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('DATABASE_URL must look like postgresql://user:pass@host/dbname');
        }
        parse_str($parts['query'] ?? '', $query);

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $parts['host'],
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '/postgres', '/'),
            is_string($query['sslmode'] ?? null) ? $query['sslmode'] : 'require'
        );

        return [$dsn, rawurldecode($parts['user'] ?? ''), rawurldecode($parts['pass'] ?? '')];
    }
}
