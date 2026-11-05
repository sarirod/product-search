<?php
declare(strict_types=1);

/**
 * Relevance tests against the REAL database + Gemini (run after bin/import.php).
 * Each case states what a human would expect to see near the top.
 *
 * Usage: php tests/relevance.php            # pass/fail per case
 *        php tests/relevance.php --verbose  # also print the top results of every query
 */

require __DIR__ . '/../src/bootstrap.php';

$verbose = in_array('--verbose', $argv, true);
$search = new Search(Db::connect(), Embedder::fromEnv());

$vacuums = ['P0201', 'P0202', 'P0203', 'P0204', 'P0205', 'P0206', 'P0207', 'P0208', 'P0209', 'P0210', 'P0212', 'P0213', 'P0214', 'P0215', 'P0987'];
$iphones = ['P0401', 'P0407', 'P0413'];

/**
 * [query, check, description]
 * check(array $ids, array $results): bool
 */
$cases = [
    ['שואב אבק', fn ($ids) => count(array_intersect(array_slice($ids, 0, 5), $vacuums)) === 5, 'top 5 are all vacuum cleaners'],
    ['vacuum cleaner', fn ($ids) => count(array_intersect(array_slice($ids, 0, 5), $vacuums)) >= 4, 'English: 4+ of top 5 are vacuums'],
    ['iPhone 16', fn ($ids) => count(array_intersect(array_slice($ids, 0, 5), $iphones)) === 3, 'all 3 Apple phones in top 5'],
    ['אייפון', fn ($ids) => count(array_intersect(array_slice($ids, 0, 5), $iphones)) === 3, 'Hebrew transliteration finds Apple phones'],
    ['מתנה למישהו שאוהב לבשל', fn ($ids, $res) => count(array_filter(array_slice($res, 0, 8), fn ($r) => $r['category'] === 'מטבח ובישול')) >= 5, '5+ of top 8 from "מטבח ובישול"'],
    ['משהו שיעזור לנקות שערות של כלב מהספה', fn ($ids) => in_array('P0950', array_slice($ids, 0, 5), true), 'pet hair brush P0950 in top 5'],
    ['Air Fryer גדול', fn ($ids) => in_array('P0001', array_slice($ids, 0, 3), true), 'mixed language: big air fryer P0001 in top 3'],
    ['שואב אבג', fn ($ids) => count(array_intersect(array_slice($ids, 0, 5), $vacuums)) >= 4, 'typo still finds vacuums'],
    ['Dyson', fn ($ids) => in_array($ids[0] ?? '', ['P0201', 'P0701', 'P0707'], true), 'brand: a Dyson product first'],
    ['KitchenAid', fn ($ids) => ($ids[0] ?? '') === 'P0005', 'brand: KitchenAid mixer first'],
    ['מתנה למישהו שאוהב קפה', fn ($ids, $res) => count(array_filter(array_slice($res, 0, 8), fn ($r) => $r['category'] === 'קפה, משקאות וגורמה')) >= 6, '6+ of top 8 coffee products'],
    ['מכונת אספרסו', fn ($ids) => in_array($ids[0] ?? '', ['P0101', 'P0108', 'P0115', 'P0104', 'P0111'], true), 'an espresso machine first'],
    ['asdfqwer', fn ($ids) => count($ids) === 0, 'gibberish returns no results'],
    ['zxqv blorp', fn ($ids) => count($ids) === 0, 'gibberish #2 returns no results'],
];

$failed = 0;
$timings = [];
foreach ($cases as [$query, $check, $description]) {
    $res = $search->search($query);
    $ids = array_column($res['results'], 'id');
    $ok = (bool) $check($ids, $res['results']);
    $failed += $ok ? 0 : 1;
    $timings[] = $res['took_ms'];
    printf("%s  %-40s %-10s %4dms  %2d results  — %s\n", $ok ? 'PASS' : 'FAIL', $query, $res['mode'], $res['took_ms'], $res['count'], $description);
    if ($verbose || !$ok) {
        foreach (array_slice($res['results'], 0, 8) as $i => $r) {
            printf("        %d. %s %s (%s)\n", $i + 1, $r['id'], $r['name'], $r['subcategory']);
        }
    }
}

sort($timings);
printf("\n%d/%d passed. Latency: median %dms, max %dms (includes cache misses)\n",
    count($cases) - $failed, count($cases), $timings[intdiv(count($timings), 2)], end($timings));
exit($failed === 0 ? 0 : 1);
