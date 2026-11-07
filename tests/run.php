<?php
declare(strict_types=1);

/**
 * Unit tests — no database or API key needed.
 * Usage: php tests/run.php
 */

require __DIR__ . '/../src/bootstrap.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function same(string $name, mixed $expected, mixed $actual): void
{
    check($name, $expected === $actual, 'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}

echo "Text::normalize\n";
same('lowercases English', 'iphone 16', Text::normalize('iPhone 16'));
same('unifies Hebrew geresh', "ג'ימבל", Text::normalize('ג׳ימבל'));
same('removes niqqud', 'שלום', Text::normalize('שָׁלוֹם'));
same('punctuation becomes space', 'שואב אבק', Text::normalize('  שואב, אבק!! '));
same('keeps inner hyphen and dot', 'usb-c 2.5 ליטר', Text::normalize('USB-C 2.5 ליטר'));
same('drops stray punctuation', 'מה זה', Text::normalize('"מה" - זה?'));
same('mixed languages', 'air fryer גדול', Text::normalize('Air Fryer גדול'));

echo "Text::tokens\n";
same('removes stopwords', ['מתנה', 'לבשל'], Text::tokens(Text::normalize('מתנה למישהו שאוהב לבשל')));
same('removes 1-char tokens and duplicates', ['שואב', 'אבק'], Text::tokens('שואב ו אבק שואב'));
same('stopwords-only query has no tokens', [], Text::tokens('משהו של מישהו'));
same('numeric tokens stay strings ("iPhone 16" used to crash)', ['iphone', '16'], Text::tokens('iphone 16'));

echo "Text::variants\n";
same('peels Hebrew prefixes', ['מהספה', 'הספה', 'ספה'], Text::variants('מהספה'));
check('plural "כלבים" also yields singular "כלב"', in_array('כלב', Text::variants('כלבים'), true));
check('original token is always the first variant', Text::variants('כלבים')[0] === 'כלבים');
same('keeps short words intact', ['ספה'], Text::variants('ספה'));
same('English untouched', ['vacuum'], Text::variants('vacuum'));
check('numeric detection', Text::isNumeric('16') && Text::isNumeric('2.5') && !Text::isNumeric('16gb'));

echo "Text::wordStartRegex\n";
$re = '/' . str_replace('/', '\/', Text::wordStartRegex('כלב')) . '/u';
check('matches word with prefix and suffix ("לכלבים")', preg_match($re, 'מיטה לכלבים גדולים') === 1);
check('does not match in the middle of a word', preg_match($re, 'אבגכלב') === 0);
$reLego = '/' . str_replace('/', '\/', Text::wordStartRegex('לגו')) . '/u';
check('short word does not match a longer word ("לגו" vs "לגוף")', preg_match($reLego, 'מכשיר אפילציה לגוף') === 0);
check('short word still matches itself with a prefix ("הלגו")', preg_match($reLego, 'ערכת הלגו הגדולה') === 1 && preg_match($reLego, 'לגו') === 1);
$reSofa = '/' . str_replace('/', '\/', Text::wordStartRegex('ספה')) . '/u';
check('short word matches before a space / at the end ("מהספה", "ספה")', preg_match($reSofa, 'שערות מהספה והשטיח') === 1 && preg_match($reSofa, 'ספה') === 1);
check('short word does not match a different longer word ("ספה" vs "ספהר")', preg_match($reSofa, 'ספהר') === 0);
check('"כלב" still matches "לכלבים" (3 letters + ים)', preg_match($re, 'מיטה לכלבים') === 1);
$reDot = '/' . str_replace('/', '\/', Text::wordStartRegex('2.5')) . '/u';
check('escapes regex characters', preg_match($reDot, 'נפח 2x5') === 0 && preg_match($reDot, 'נפח 2.5 ליטר') === 1);

echo "Search::fuse (Reciprocal Rank Fusion)\n";
same('item in both lists wins', 'B', Search::fuse([['A', 'B', 'C'], ['B', 'D']], 10)[0]);
same('union of both lists', ['A', 'B', 'C', 'D'], (function () { $r = Search::fuse([['A', 'B', 'C'], ['B', 'D']], 10); sort($r); return $r; })());
same('respects limit', 2, count(Search::fuse([['A', 'B', 'C']], 2)));
same('empty lists', [], Search::fuse([[], []], 10));
same('ties keep vector order first', ['A', 'X'], Search::fuse([['A'], ['X']], 10));

echo "Search::applyRelevanceCutoff\n";
same('drops below absolute floor', [], Search::applyRelevanceCutoff(['A' => Search::MIN_SIMILARITY - 0.01]));
// 50 candidates: a few stand out at the top, the rest form a low "background".
$cands = ['A' => 0.80, 'B' => 0.78, 'C' => 0.80 - Search::RELATIVE_GAP - 0.01];
for ($i = count($cands); $i < Search::CANDIDATES; $i++) { $cands["bg$i"] = 0.62; }
same('keeps close-to-best, drops far ones', ['A', 'B'], array_keys(Search::applyRelevanceCutoff($cands)));
// Flat distribution (meaningless query like "הגדרות"): everything within MIN_SPREAD -> nothing.
$flat = [];
for ($i = 0; $i < Search::CANDIDATES; $i++) { $flat["F$i"] = 0.652 - $i * 0.0004; }
same('flat distribution returns nothing', [], Search::applyRelevanceCutoff($flat));
// Narrow spread: the gap shrinks to half the spread, so only the top cluster survives.
$narrow = ['T1' => 0.660, 'T2' => 0.650, 'T3' => 0.635];
for ($i = count($narrow); $i < Search::CANDIDATES; $i++) { $narrow["N$i"] = 0.630 - $i * 0.0003; } // spread ~0.045 -> floor ~0.638
same('narrow spread keeps only the top cluster', ['T1', 'T2'], array_keys(Search::applyRelevanceCutoff($narrow)));

echo "Search::filterKeywordHits\n";
$kw = ['FULL' => 3, 'PARTIAL_IN_POOL' => 2, 'PARTIAL_NOISE' => 1];
same('partial match kept only if meaning search also has it', ['FULL', 'PARTIAL_IN_POOL'], array_keys(Search::filterKeywordHits($kw, 3, ['PARTIAL_IN_POOL' => 0.61])));
same('2 of 3 words is a strong match even without meaning support', ['FULL', 'PARTIAL_IN_POOL'], array_keys(Search::filterKeywordHits($kw, 3, [])));
same('1 of 2 words needs meaning support', ['B'], array_keys(Search::filterKeywordHits(['A' => 1, 'B' => 2], 2, [])));
same('keyword-only mode keeps everything', array_keys($kw), array_keys(Search::filterKeywordHits($kw, 3, null)));
same('number-only query keeps everything', ['N'], array_keys(Search::filterKeywordHits(['N' => 0], 0, [])));

echo "Db::parseUrl\n";
[$dsn, $user, $pass] = Db::parseUrl('postgresql://alice:p%40ss@ep-cool-1.eu-central-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require');
same('dsn', 'pgsql:host=ep-cool-1.eu-central-1.aws.neon.tech;port=5432;dbname=neondb;sslmode=require', $dsn);
same('user', 'alice', $user);
same('url-encoded password', 'p@ss', $pass);
$threw = false;
try { Db::parseUrl('mysql://x@y/z'); } catch (InvalidArgumentException) { $threw = true; }
check('rejects non-postgres URL', $threw);

echo "Catalog data files\n";
$root = dirname(__DIR__);
$rows = Catalog::load("$root/data/catalog.csv", "$root/data/prices.csv", "$root/data/enrichment.csv");
same('1000 products loaded', 1000, count($rows));
same('ids unique', 1000, count(array_unique(array_column($rows, 'id'))));
check('every product has a positive price', count(array_filter($rows, fn ($r) => $r['price'] > 0)) === 1000);
check('every product has a needs line', count(array_filter($rows, fn ($r) => $r['needs'] !== '')) === 1000);
$p1 = $rows[0];
same('first row parsed (quoted description with commas)', 'P0001', $p1['id']);
check('description with commas kept whole', str_contains($p1['description'], ',') && str_ends_with($p1['description'], '.'));
check('embedding text contains name, category and needs', str_contains(Catalog::embeddingText($p1), $p1['name']) && str_contains(Catalog::embeddingText($p1), 'מתאים ל:'));

$tmp = tempnam(sys_get_temp_dir(), 'prices');
file_put_contents($tmp, "id,price\nP0001,0\n");
$threw = false;
try { Catalog::load("$root/data/catalog.csv", $tmp, "$root/data/enrichment.csv"); } catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), 'invalid price'); }
unlink($tmp);
check('validation rejects missing / zero prices', $threw);

echo "\nEmbedder::unitVector\n";
$u = Embedder::unitVector([3.0, 4.0]);
check('normalizes to length 1', abs($u[0] - 0.6) < 1e-9 && abs($u[1] - 0.8) < 1e-9);
same('pgvector literal', '[0.6000000,0.8000000]', Embedder::toPgVector($u));

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
