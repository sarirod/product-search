<?php
declare(strict_types=1);

/**
 * Exploratory search check: runs a long list of real-life and "weird" queries (Hebrew + English)
 * and prints the top results with their subcategory, so a human can spot noise at a glance.
 * Also fails loudly on any exception (e.g. the old "iPhone 16" crash).
 *
 * Usage: php tests/explore.php            # all queries
 *        php tests/explore.php "query" ... # only the given queries
 */

require __DIR__ . '/../src/bootstrap.php';

$queries = array_slice($argv, 1) ?: [
    // --- Searches done manually during testing, and their English twins ---
    'הגדרות', 'settings',
    'אני צריכה מעמד לטלפונים חכמים', 'I need a stand for smartphones',
    'חתול', 'cat',
    'טלפון', 'phone',
    'אפרון ללימוד מגע לילדים', 'touch learning kit for kids',
    'לגו', 'lego',
    'כינור', 'violin',
    'מתנה למישהו שאוהב לנגן על כינור', 'gift for someone who loves playing the violin',
    'קשת לכינור', 'violin bow',
    'משחקי מחשב למתחילים', 'computer games for beginners',
    'הגדרו g',
    // --- The examples from the assignment, in both languages ---
    'שואב אבק', 'vacuum cleaner', 'iPhone 16', 'אייפון 16', 'אייפון', 'iphone',
    'מתנה למישהו שאוהב לבשל', 'gift for someone who loves to cook',
    'משהו שיעזור לנקות שערות של כלב מהספה', 'something to clean dog hair off the couch',
    // --- Brands, transliterations, typos, mixed language ---
    'סמסונג', 'samsung', 'אפל', 'apple', 'אייפד', 'ipad', 'דייסון', 'dysn', 'ipone', 'air frier', 'אייר פרייר',
    'מכונת espresso', 'קפה beans', 'VACUUM', 'שןפיםמק' /* "iphone" typed on a Hebrew keyboard */,
    // --- Needs / intents ---
    'מתנה לאמא', 'gift for mom', 'משהו לחורף', 'something for winter', 'אני לא מצליחה לישון', "I can't sleep",
    'קמפינג', 'camping', 'תינוק', 'baby', 'כאב גב', 'back pain', 'אוזניות זולות', 'cheap headphones',
    'מחשב נייד', 'laptop', 'מכונת כביסה', 'washing machine', 'מתנה לחתונה', 'wedding gift',
    // --- Things that are not in the catalog ---
    'פיצה', 'pizza', 'נעלי ריצה', 'running shoes', 'מכונית', 'car', 'ביטוח רכב', 'car insurance',
    // --- Garbage / edge cases ---
    'asdfqwer', 'zxqv blorp', 'אאאאא', '123', '16', 'a', '?', '....', '😀', '<script>alert(1)</script>',
    'SELECT * FROM products', 'שָׁלוֹם', str_repeat('שואב אבק ', 40),
];

$search = new Search(Db::connect(), Embedder::fromEnv());
$failed = 0;
foreach ($queries as $q) {
    try {
        $r = $search->search($q);
    } catch (Throwable $e) {
        $failed++;
        printf("\n!! %s  =>  EXCEPTION %s: %s\n", $q, get_class($e), $e->getMessage());
        continue;
    }
    printf("\n== %s  [%s, %d results, %dms]\n", mb_strimwidth($q, 0, 60, '…'), $r['mode'], $r['count'], $r['took_ms']);
    foreach (array_slice($r['results'], 0, 8) as $i => $p) {
        printf("   %d. %s %s  (%s)\n", $i + 1, $p['id'], $p['name'], $p['subcategory']);
    }
}
printf("\n%d queries, %d exceptions\n", count($queries), $failed);
exit($failed === 0 ? 0 : 1);
