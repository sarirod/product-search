<?php
declare(strict_types=1);

/**
 * Text helpers shared by the importer (product side) and the search (query side).
 * Both sides MUST normalize the same way, otherwise keyword matching breaks.
 */
final class Text
{
    /** Hebrew one-letter prefixes: ו ה ל ב מ ש כ ("and", "the", "to", "in", "from", "that", "like"). */
    public const HEB_PREFIXES = 'ובלמשהכ';

    /** Filler words that carry no product meaning. Meaning-based search is handled by embeddings. */
    private const STOPWORDS = [
        // Hebrew
        'של', 'את', 'עם', 'על', 'אל', 'מה', 'מי', 'זה', 'זו', 'זאת', 'או', 'גם', 'כי', 'אם', 'לא', 'יש', 'אין',
        'אני', 'הוא', 'היא', 'הם', 'הן', 'אנחנו', 'לי', 'לו', 'לה', 'לנו', 'להם', 'כל', 'רק', 'עוד', 'מאוד', 'הכי',
        'משהו', 'משהי', 'מישהו', 'מישהי', 'למישהו', 'למישהי', 'שיעזור', 'שתעזור', 'יעזור', 'לעזור', 'שיכול',
        'צריך', 'צריכה', 'רוצה', 'מחפש', 'מחפשת', 'אשמח', 'בבקשה', 'דבר', 'עבור', 'בשביל', 'כמו', 'יותר', 'פחות',
        'שאוהב', 'שאוהבת', 'אוהב', 'אוהבת', 'שאוהבים', 'טוב', 'טובה', 'ממש',
        // English
        'a', 'an', 'the', 'for', 'to', 'of', 'and', 'or', 'with', 'in', 'on', 'at', 'my', 'me', 'i', 'is', 'it',
        'something', 'someone', 'somebody', 'who', 'that', 'which', 'best', 'good', 'help', 'want', 'need',
        'looking', 'loves', 'love', 'likes', 'like',
    ];

    /**
     * Lowercase, remove Hebrew niqqud, unify geresh/quote characters, collapse punctuation to spaces.
     * Keeps letters, digits and the characters ' " . - + that appear inside product words
     * (e.g. ג'ימבל, 2.5, USB-C, A+).
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = (string) preg_replace('/[\x{0591}-\x{05C7}]/u', '', $s);      // niqqud & cantillation marks
        $s = strtr($s, ['׳' => "'", '״' => '"', '’' => "'", '‘' => "'", '`' => "'", '“' => '"', '”' => '"']);
        $s = (string) preg_replace('/[^\p{L}\p{N}\'".\-+]+/u', ' ', $s);  // everything else -> space
        $s = (string) preg_replace('/(?<![\p{L}\p{N}])[\'".\-+]+|[\'".\-+]+(?![\p{L}\p{N}])/u', ' ', $s); // stray punctuation
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * Split a normalized query into meaningful tokens (no stopwords, no 1-char tokens, no duplicates).
     *
     * @return list<string>
     */
    public static function tokens(string $normalized): array
    {
        $out = [];
        foreach (explode(' ', $normalized) as $t) {
            if (mb_strlen($t) < 2 || in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            $out[$t] = true;
        }
        // array_keys() would turn numeric tokens like "16" into ints — keep them strings.
        return array_map('strval', array_keys($out));
    }

    /**
     * Spelling variants of a Hebrew token so that "מהספה" also finds "ספה" and "כלבים" also finds "כלב".
     * Prefixes are peeled one letter at a time (max 2); plural suffixes ים/ות are dropped.
     * Variants shorter than 3 letters are discarded to avoid noisy matches.
     *
     * @return list<string>
     */
    public static function variants(string $token): array
    {
        $variants = [$token];
        if (!preg_match('/^\p{Hebrew}+$/u', $token)) {
            return $variants;
        }
        $current = $token;
        for ($i = 0; $i < 2; $i++) {
            $first = mb_substr($current, 0, 1);
            $rest = mb_substr($current, 1);
            if (!str_contains(self::HEB_PREFIXES, $first) || mb_strlen($rest) < 3) {
                break;
            }
            $variants[] = $current = $rest;
        }
        foreach ($variants as $v) {
            if (mb_strlen($v) >= 5 && preg_match('/(ים|ות)$/u', $v)) {
                $variants[] = mb_substr($v, 0, -2);
            }
        }
        return array_values(array_unique($variants));
    }

    /** True for tokens like "16", "128", "2.5" — they get a low weight in keyword matching. */
    public static function isNumeric(string $token): bool
    {
        return (bool) preg_match('/^[\d.]+$/', $token);
    }

    /**
     * Postgres regex (ARE) that matches a token at the START of a word in normalized text,
     * optionally preceded by up to 2 Hebrew prefix letters. Suffixes are allowed ("כלב" ~ "לכלבים").
     */
    public static function wordStartRegex(string $token): string
    {
        $alts = array_map(
            static function (string $v) use ($token): string {
                $escaped = (string) preg_replace('/[\\\\.^$|?*+()\[\]{}]/', '\\\\$0', $v);
                $hebrew = (bool) preg_match('/^\p{Hebrew}+$/u', $v);
                // A stem left after peeling prefix letters is only a guess ("מכונית" -> "ונית"), and short
                // words are too permissive as prefixes ("לגו" would match "לגוף", "cat" would match "catan").
                // Both must be followed only by a real inflection ending, then the end of the word.
                $peeled = !str_starts_with($token, $v);
                if ($hebrew && ($peeled || mb_strlen($v) <= 3)) {
                    return $escaped . self::SHORT_WORD_ENDING;
                }
                if (!$hebrew && !self::isNumeric($v) && mb_strlen($v) <= self::SHORT_LATIN_MAX) {
                    return $escaped . self::SHORT_LATIN_ENDING;
                }
                return $escaped;
            },
            self::variants($token)
        );
        return '(^|[ -])[' . self::HEB_PREFIXES . ']{0,2}(' . implode('|', $alts) . ')';
    }

    /** Optional Hebrew suffix (plural / feminine / possessive), then end of word. */
    private const SHORT_WORD_ENDING = '(ים|ות|ה|ת|י|ו|יות|ית)?($|[^א-תa-z0-9])';

    /**
     * English words up to this length must be whole words: short words are too often the start of an
     * unrelated longer word ("cat" ~ "catan", "baby" ~ the brand "babyliss", "back" ~ "backpack").
     */
    private const SHORT_LATIN_MAX = 4;

    /** Optional English ending, then end of word ("cat" ~ "cats", "cook" ~ "cooking"). */
    private const SHORT_LATIN_ENDING = '(s|es|ing|er|ers|ed)?($|[^א-תa-z0-9])';

    /** Only English words of 4+ letters are spell-corrected (shorter words have too many neighbours). */
    public static function isCorrectable(string $token): bool
    {
        return (bool) preg_match('/^[a-z]{4,}$/', $token);
    }

    /**
     * Spelling correction for an English word that does not appear in the catalog at all:
     * returns the closest catalog word ("dysn" -> "dyson", "ipone" -> "iphone"), or null.
     * Allowed edit distance: 1 for words of 4-6 letters, 2 for longer words. Ties go to the more
     * common word. Hebrew is left to the meaning search (Hebrew typos are already handled well there).
     *
     * @param array<string,int> $vocabulary catalog word => number of products that contain it
     */
    public static function closestWord(string $token, array $vocabulary): ?string
    {
        if (!self::isCorrectable($token)) {
            return null;
        }
        $length = strlen($token);
        foreach ($vocabulary as $word => $count) {
            if (str_starts_with((string) $word, $token)) {
                return null; // a known word (or the start of one): nothing to correct
            }
        }
        $maxDistance = $length >= 7 ? 2 : 1;
        $best = null;
        $bestKey = null;
        foreach ($vocabulary as $word => $count) {
            $word = (string) $word;
            if (abs(strlen($word) - $length) > $maxDistance) {
                continue;
            }
            $distance = levenshtein($token, $word);
            $key = [$distance, -$count, $word];
            if ($distance <= $maxDistance && ($bestKey === null || $key < $bestKey)) {
                [$best, $bestKey] = [$word, $key];
            }
        }
        return $best;
    }
}
