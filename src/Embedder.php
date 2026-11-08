<?php
declare(strict_types=1);

/**
 * Turns text into a "meaning vector" (embedding) using Google's Gemini embedding API.
 * Texts with similar meaning get vectors that are close to each other — across languages too,
 * which is what lets "אייפון" find a product called "סמארטפון ... Apple".
 *
 * Plain cURL, no SDK. The API key is read from the environment and never logged.
 */
final class Embedder
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const DEFAULT_MODEL = 'gemini-embedding-001';

    /** Seconds. A search query must be fast (else we fall back to keywords); an import batch may take longer. */
    private const QUERY_TIMEOUT = 3.0;
    private const BATCH_TIMEOUT = 60.0;

    public function __construct(
        private readonly string $apiKey,
        public readonly string $model = self::DEFAULT_MODEL,
        public readonly int $dimensions = 768,
    ) {
    }

    /** Returns null when no API key is configured — search then runs in keyword-only mode. */
    public static function fromEnv(): ?self
    {
        $key = Env::get('GEMINI_API_KEY');
        return $key === null ? null : new self($key, Env::get('GEMINI_EMBED_MODEL') ?? self::DEFAULT_MODEL);
    }

    /**
     * Embedding for a user's search query.
     *
     * @return list<float>
     */
    public function embedQuery(string $text): array
    {
        $response = $this->post(':embedContent', [
            'content'              => ['parts' => [['text' => $text]]],
            'taskType'             => 'RETRIEVAL_QUERY',
            'outputDimensionality' => $this->dimensions,
        ], self::QUERY_TIMEOUT);

        return self::unitVector($this->extractValues($response['embedding'] ?? null));
    }

    /**
     * Embeddings for many product texts in one request (used by the importer).
     *
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedDocuments(array $texts): array
    {
        $requests = array_map(fn (string $t): array => [
            'model'                => 'models/' . $this->model,
            'content'              => ['parts' => [['text' => $t]]],
            'taskType'             => 'RETRIEVAL_DOCUMENT',
            'outputDimensionality' => $this->dimensions,
        ], $texts);

        $response = $this->post(':batchEmbedContents', ['requests' => $requests], self::BATCH_TIMEOUT);
        $embeddings = $response['embeddings'] ?? null;
        if (!is_array($embeddings) || count($embeddings) !== count($texts)) {
            throw new RuntimeException('Gemini returned an unexpected number of embeddings');
        }
        return array_map(fn ($e): array => self::unitVector($this->extractValues($e)), $embeddings);
    }

    /**
     * Gemini only pre-normalizes 3072-dim vectors, so we normalize to length 1 ourselves.
     *
     * @param list<float> $v
     * @return list<float>
     */
    public static function unitVector(array $v): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $v)));
        if ($norm == 0.0) {
            throw new RuntimeException('Received a zero-length embedding');
        }
        return array_map(static fn (float $x): float => $x / $norm, $v);
    }

    /** pgvector literal, e.g. "[0.12,-0.03,...]". */
    public static function toPgVector(array $v): string
    {
        return '[' . implode(',', array_map(static fn (float $x): string => sprintf('%.7F', $x), $v)) . ']';
    }

    /** @return list<float> */
    private function extractValues(mixed $embedding): array
    {
        $values = is_array($embedding) ? ($embedding['values'] ?? null) : null;
        if (!is_array($values) || count($values) !== $this->dimensions) {
            throw new RuntimeException('Gemini returned an embedding with unexpected shape');
        }
        return array_map('floatval', $values);
    }

    /** @return array<string,mixed> */
    private function post(string $method, array $body, float $timeoutSeconds): array
    {
        $ch = curl_init(self::BASE_URL . rawurlencode($this->model) . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER        => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(2000, $timeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS        => (int) ($timeoutSeconds * 1000),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Gemini request failed: ' . $curlError);
        }
        $data = json_decode((string) $raw, true);
        if ($status === 429) {
            $reason = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            $daily = stripos($reason, 'per day') !== false || stripos($reason, 'PerDay') !== false;
            $short = $daily ? 'daily quota exhausted' : 'per-minute limit';
            throw new EmbedderRateLimited("Gemini rate limit reached (HTTP 429, $short)", $daily);
        }
        if ($status !== 200 || !is_array($data)) {
            $message = is_array($data) ? (string) ($data['error']['message'] ?? 'unknown error') : 'invalid JSON';
            throw new RuntimeException("Gemini HTTP $status: $message");
        }
        return $data;
    }
}
