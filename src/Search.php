<?php
declare(strict_types=1);

/**
 * Hybrid product search.
 *
 *   1. Meaning:  the query is embedded (Gemini) and compared with every product vector (pgvector).
 *   2. Keywords: query words are matched against name / brand / category / needs / description.
 *   3. Fusion:   both ranked lists are merged with Reciprocal Rank Fusion (RRF).
 *
 * If Gemini is unavailable the search keeps working in keyword-only mode.
 */
final class Search
{
    public const MAX_QUERY_LENGTH = 200;
    public const MAX_RESULTS = 24;

    /** How many candidates each method contributes before fusion. */
    public const CANDIDATES = 50;

    /** RRF constant: 60 is the standard value from the original paper (Cormack et al., 2009). */
    private const RRF_K = 60;

    /**
     * Relevance cut-offs for the meaning search (cosine similarity, 1 = identical).
     * Calibrated on real queries — see README "How it was tested".
     * With Gemini embeddings even unrelated Hebrew texts score ~0.60, so what matters is
     * whether the best matches STAND OUT from the rest, not the raw score.
     *  - MIN_SIMILARITY: below this a product is not related to the query at all.
     *  - MIN_SPREAD:     if the best and the 50th candidate are this close, nothing stands out
     *                    (e.g. "הגדרות" / "settings") -> no meaning-based results at all.
     *  - RELATIVE_GAP:   keep only products close to the best match (at most half the spread).
     */
    public const MIN_SIMILARITY = 0.60;
    public const MIN_SPREAD = 0.03;
    public const RELATIVE_GAP = 0.06;

    /** Keyword weight per field: a word in the product name counts more than in the description. */
    private const FIELD_WEIGHTS = ['name_norm' => 3.0, 'brand_norm' => 3.0, 'cat_norm' => 2.0, 'needs_norm' => 1.5, 'desc_norm' => 1.0];

    public function __construct(
        private readonly PDO $db,
        private readonly ?Embedder $embedder,
    ) {
    }

    /**
     * @return array{query:string, mode:string, took_ms:int, count:int, results:list<array<string,mixed>>}
     */
    public function search(string $rawQuery): array
    {
        $started = hrtime(true);
        $normalized = Text::normalize(mb_substr($rawQuery, 0, self::MAX_QUERY_LENGTH));
        $tokens = Text::tokens($normalized);

        $vectorPool = null; // all vector candidates (before the cut-off); null = meaning search unavailable
        $mode = 'keyword';
        if ($this->embedder !== null && $normalized !== '') {
            try {
                $vectorPool = $this->vectorCandidates($this->queryVector($normalized));
                $mode = 'hybrid';
            } catch (Throwable $e) {
                // Degrade gracefully: log the reason (never the key) and continue with keywords.
                error_log('[search] embedding unavailable, keyword fallback: ' . $e->getMessage());
            }
        }
        $vectorHits = self::applyRelevanceCutoff($vectorPool ?? []);
        $wordTokens = count(array_filter($tokens, static fn (string $t): bool => !Text::isNumeric($t)));
        $keywordHits = self::filterKeywordHits($tokens ? $this->keywordCandidates($tokens) : [], $wordTokens, $vectorPool === null ? null : $vectorHits);

        $ids = self::fuse([array_keys($vectorHits), array_keys($keywordHits)], self::MAX_RESULTS);
        $results = $this->fetchProducts($ids);

        return [
            'query'   => $rawQuery,
            'mode'    => $mode,
            'took_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'count'   => count($results),
            'results' => $results,
        ];
    }

    /**
     * Reciprocal Rank Fusion: score(product) = Σ 1 / (K + rank in each list).
     * Rewards products ranked high by either method, and most of all by both.
     *
     * @param list<list<string>> $rankedLists
     * @return list<string>
     */
    public static function fuse(array $rankedLists, int $limit): array
    {
        $scores = [];
        foreach ($rankedLists as $list) {
            foreach (array_values($list) as $i => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (self::RRF_K + $i + 1);
            }
        }
        // Stable sort: on equal score keep first-seen order (vector list comes first).
        $order = array_flip(array_keys($scores));
        uksort($scores, static fn ($a, $b) => [$scores[$b], $order[$a]] <=> [$scores[$a], $order[$b]]);
        return array_slice(array_map('strval', array_keys($scores)), 0, $limit);
    }

    /**
     * Keep the vector hits that clearly stand out (see the constants above).
     *
     * @param array<string,float> $similarities id => cosine similarity, best first (all candidates)
     * @return array<string,float>
     */
    public static function applyRelevanceCutoff(array $similarities): array
    {
        if (!$similarities) {
            return [];
        }
        $best = reset($similarities);
        $spread = $best - end($similarities);
        if (count($similarities) >= self::CANDIDATES && $spread < self::MIN_SPREAD) {
            return []; // flat distribution: the query has no clear meaning in this catalog
        }
        $floor = max(self::MIN_SIMILARITY, $best - min(self::RELATIVE_GAP, $spread / 2));
        return array_filter($similarities, static fn (float $s): bool => $s >= $floor);
    }

    /**
     * A keyword hit that matched only SOME of the query words is kept only if the meaning search
     * also considers it a candidate. This removes noise such as "מד חום מגע" for
     * "ערכת לימוד מגע לילדים" (only the word "מגע" matched, and the meaning is unrelated).
     * Without meaning search ($vectorPool === null) all keyword hits are kept.
     *
     * @param array<string,int>        $keywordMatched id => number of query words matched, best first
     * @param array<string,float>|null $vectorPool     id => similarity of all vector candidates
     * @return array<string,int>
     */
    public static function filterKeywordHits(array $keywordMatched, int $wordTokens, ?array $vectorPool): array
    {
        if ($vectorPool === null) {
            return $keywordMatched;
        }
        // "Strong" keyword hit: all words, or at least 2 words and ~60% of them
        // ("משחקי מחשב למתחילים" -> a gaming laptop matching "משחק" + "מחשב" is kept).
        $strong = min($wordTokens, max(2, (int) ceil($wordTokens * 0.6)));
        return array_filter(
            $keywordMatched,
            static fn (int $matched, string $id): bool => $matched >= $strong || isset($vectorPool[$id]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** Query vector as a pgvector literal; cached in the DB so repeated searches skip the API call. */
    private function queryVector(string $normalized): string
    {
        $model = $this->embedder->model . '/' . $this->embedder->dimensions;
        $stmt = $this->db->prepare('SELECT embedding::text FROM query_embeddings WHERE model = ? AND query_norm = ?');
        $stmt->execute([$model, $normalized]);
        $cached = $stmt->fetchColumn();
        if (is_string($cached)) {
            return $cached;
        }

        $vector = Embedder::toPgVector($this->embedder->embedQuery($normalized));
        $this->db->prepare(
            'INSERT INTO query_embeddings (model, query_norm, embedding) VALUES (?, ?, CAST(? AS vector))
             ON CONFLICT (model, query_norm) DO NOTHING'
        )->execute([$model, $normalized, $vector]);
        return $vector;
    }

    /** @return array<string,float> id => similarity of the top candidates, best first (before the cut-off) */
    private function vectorCandidates(string $vector): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, 1 - (embedding <=> CAST(:v AS vector)) AS similarity
               FROM products
              WHERE embedding IS NOT NULL
              ORDER BY similarity DESC
              LIMIT ' . self::CANDIDATES
        );
        $stmt->execute(['v' => $vector]);
        $similarities = [];
        foreach ($stmt as $row) {
            $similarities[(string) $row['id']] = (float) $row['similarity'];
        }
        return $similarities;
    }

    /**
     * Each query word becomes a regex that matches the start of a word (Hebrew prefixes allowed).
     * A product must match at least half of the meaningful words; numbers ("16", "128") count less.
     *
     * @param list<string> $tokens
     * @return array<string,int> id => number of query words matched, best first
     */
    private function keywordCandidates(array $tokens): array
    {
        $terms = [];
        $wordTokens = 0;
        foreach ($tokens as $t) {
            $numeric = Text::isNumeric($t);
            $wordTokens += $numeric ? 0 : 1;
            $terms[] = ['re' => Text::wordStartRegex($t), 'wt' => $numeric ? 0.3 : 1.0, 'counts' => $numeric ? 0 : 1];
        }
        $minMatched = max(1, (int) ceil($wordTokens * 0.5));

        $cases = '';
        foreach (self::FIELD_WEIGHTS as $field => $weight) {
            $cases .= " WHEN p.$field ~ t.re THEN $weight";
        }
        $sql = "
            WITH t AS (
                SELECT e->>'re' AS re, (e->>'wt')::float AS wt, (e->>'counts')::int AS counts
                  FROM json_array_elements(CAST(:terms AS json)) AS e
            ), hits AS (
                SELECT p.id, t.wt, t.counts, CASE $cases ELSE 0 END AS w
                  FROM products p CROSS JOIN t
            )
            SELECT id,
                   SUM(counts) FILTER (WHERE w > 0) AS matched,
                   SUM(w * wt) AS score
              FROM hits
             GROUP BY id
            HAVING SUM(w) > 0 AND COALESCE(SUM(counts) FILTER (WHERE w > 0), 0) >= :min
             ORDER BY matched DESC, score DESC, id
             LIMIT " . self::CANDIDATES;

        // Pure-number queries (e.g. "128") have no word tokens: then any match is enough.
        $stmt = $this->db->prepare($wordTokens === 0 ? str_replace('>= :min', '>= 0', $sql) : $sql);
        $params = ['terms' => json_encode($terms, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        if ($wordTokens > 0) {
            $params['min'] = $minMatched;
        }
        $stmt->execute($params);

        $out = [];
        foreach ($stmt as $row) {
            $out[(string) $row['id']] = (int) $row['matched'];
        }
        return $out;
    }

    /**
     * @param list<string> $ids in display order
     * @return list<array<string,mixed>>
     */
    private function fetchProducts(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id, name, brand, category, subcategory, price, image_url
               FROM products
              WHERE id IN (SELECT json_array_elements_text(CAST(:ids AS json)))'
        );
        $stmt->execute(['ids' => json_encode($ids, JSON_THROW_ON_ERROR)]);
        $byId = [];
        foreach ($stmt as $row) {
            $row['price'] = (float) $row['price'];
            $row['brand'] = $row['brand'] ?? '';
            $byId[$row['id']] = $row;
        }
        return array_values(array_filter(array_map(static fn ($id) => $byId[$id] ?? null, $ids)));
    }
}
