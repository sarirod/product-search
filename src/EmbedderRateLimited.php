<?php
declare(strict_types=1);

/**
 * Thrown on HTTP 429. A per-minute limit is worth waiting for; a per-day quota is not
 * (the importer stops and tells you when to re-run).
 */
final class EmbedderRateLimited extends RuntimeException
{
    public function __construct(string $message, public readonly bool $dailyQuota = false)
    {
        parent::__construct($message);
    }
}
