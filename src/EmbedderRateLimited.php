<?php
declare(strict_types=1);

/** Thrown on HTTP 429 so the importer can wait and retry. */
final class EmbedderRateLimited extends RuntimeException
{
}
