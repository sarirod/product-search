<?php
declare(strict_types=1);

/**
 * Loads the catalog into Postgres and computes embeddings.
 *
 * Usage:
 *   php bin/import.php                     # uses data/catalog.csv, data/prices.csv, data/enrichment.csv
 *   php bin/import.php path/to/catalog.csv # custom catalog file
 *
 * Idempotent: running it again changes nothing unless the data changed.
 * Only products whose text changed are re-embedded (tracked by content_hash).
 */

require __DIR__ . '/../src/bootstrap.php';

const BATCH_SIZE = 50;
const MAX_RETRIES = 6;

$root = dirname(__DIR__);
$catalogPath = $argv[1] ?? "$root/data/catalog.csv";

try {
    $products = Catalog::load($catalogPath, "$root/data/prices.csv", "$root/data/enrichment.csv");
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo 'Validated ' . count($products) . " products.\n";

$embedder = Embedder::fromEnv();
if ($embedder === null) {
    fwrite(STDERR, "GEMINI_API_KEY is not set — cannot compute embeddings.\n");
    exit(1);
}
$db = Db::connect();

// 1) Upsert all rows in one transaction. If a product's text changed, its embedding is cleared.
$existing = $db->query('SELECT id, content_hash FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
$upsert = $db->prepare(
    'INSERT INTO products (id, name, brand, category, subcategory, description, attributes, image_url, price, needs,
                           name_norm, brand_norm, cat_norm, needs_norm, desc_norm, content_hash)
     VALUES (:id, :name, :brand, :category, :subcategory, :description, :attributes, :image_url, :price, :needs,
             :name_norm, :brand_norm, :cat_norm, :needs_norm, :desc_norm, :content_hash)
     ON CONFLICT (id) DO UPDATE SET
        name = EXCLUDED.name, brand = EXCLUDED.brand, category = EXCLUDED.category,
        subcategory = EXCLUDED.subcategory, description = EXCLUDED.description, attributes = EXCLUDED.attributes,
        image_url = EXCLUDED.image_url, price = EXCLUDED.price, needs = EXCLUDED.needs,
        name_norm = EXCLUDED.name_norm, brand_norm = EXCLUDED.brand_norm, cat_norm = EXCLUDED.cat_norm,
        needs_norm = EXCLUDED.needs_norm, desc_norm = EXCLUDED.desc_norm,
        embedding = CASE WHEN products.content_hash = EXCLUDED.content_hash THEN products.embedding END,
        content_hash = EXCLUDED.content_hash,
        updated_at = now()
     WHERE products.content_hash IS DISTINCT FROM EXCLUDED.content_hash
        OR products.price IS DISTINCT FROM EXCLUDED.price
        OR products.image_url IS DISTINCT FROM EXCLUDED.image_url
        OR products.name_norm IS DISTINCT FROM EXCLUDED.name_norm
        OR products.brand_norm IS DISTINCT FROM EXCLUDED.brand_norm
        OR products.cat_norm IS DISTINCT FROM EXCLUDED.cat_norm
        OR products.needs_norm IS DISTINCT FROM EXCLUDED.needs_norm
        OR products.desc_norm IS DISTINCT FROM EXCLUDED.desc_norm'
);

$texts = [];
$stats = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'embedded' => 0];
$db->beginTransaction();
foreach ($products as $p) {
    $texts[$p['id']] = Catalog::embeddingText($p);
    $hash = hash('sha256', $embedder->model . '|' . $embedder->dimensions . '|' . $texts[$p['id']]);
    $upsert->execute([
        'id' => $p['id'], 'name' => $p['name'], 'brand' => $p['brand'], 'category' => $p['category'],
        'subcategory' => $p['subcategory'], 'description' => $p['description'], 'attributes' => $p['attributes'],
        'image_url' => $p['image_url'], 'price' => $p['price'], 'needs' => $p['needs'],
        'name_norm' => Text::normalize($p['name']),
        'brand_norm' => Text::normalize($p['brand']),
        'cat_norm' => Text::normalize($p['category'] . ' ' . $p['subcategory']),
        'needs_norm' => Text::normalize($p['needs']),
        'desc_norm' => Text::normalize($p['description'] . ' ' . $p['attributes']),
        'content_hash' => $hash,
    ]);
    if (!isset($existing[$p['id']])) {
        $stats['added']++;
    } elseif ($upsert->rowCount() > 0) {
        $stats['updated']++;
    } else {
        $stats['unchanged']++;
    }
}
// Products removed from the CSV are removed from the DB as well.
$ids = json_encode(array_keys($texts), JSON_THROW_ON_ERROR);
$delete = $db->prepare('DELETE FROM products WHERE id NOT IN (SELECT json_array_elements_text(CAST(? AS json)))');
$delete->execute([$ids]);
$stats['deleted'] = $delete->rowCount();
$db->commit();

// 2) Embed every product that has no embedding yet, in batches, committing each batch.
$missing = $db->query('SELECT id FROM products WHERE embedding IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$update = $db->prepare('UPDATE products SET embedding = CAST(? AS vector) WHERE id = ?');
foreach (array_chunk($missing, BATCH_SIZE) as $n => $batch) {
    for ($attempt = 1; ; $attempt++) {
        try {
            $vectors = $embedder->embedDocuments(array_map(static fn ($id) => $texts[$id], $batch));
            break;
        } catch (RuntimeException $e) {
            if ($e instanceof EmbedderRateLimited && $e->dailyQuota) {
                fwrite(STDERR, "Gemini's daily embedding quota is used up — retrying today won't help.\n");
                fwrite(STDERR, "Already-embedded batches are saved ({$stats['embedded']} this run). Re-run after the quota resets (midnight US Pacific time).\n");
                exit(1);
            }
            if ($attempt >= MAX_RETRIES) {
                fwrite(STDERR, "Embedding failed after $attempt attempts: {$e->getMessage()}\n");
                fwrite(STDERR, "Already-embedded batches are saved. Re-run the script to continue.\n");
                exit(1);
            }
            $wait = $e instanceof EmbedderRateLimited ? 15 * $attempt : 2 ** $attempt;
            echo "  batch " . ($n + 1) . ": {$e->getMessage()} — retrying in {$wait}s\n";
            sleep($wait);
        }
    }
    $db->beginTransaction();
    foreach ($batch as $i => $id) {
        $update->execute([Embedder::toPgVector($vectors[$i]), $id]);
    }
    $db->commit();
    $stats['embedded'] += count($batch);
    echo "  embedded {$stats['embedded']}/" . count($missing) . "\n";
}

$total = (int) $db->query('SELECT count(*) FROM products')->fetchColumn();
$withEmbedding = (int) $db->query('SELECT count(*) FROM products WHERE embedding IS NOT NULL')->fetchColumn();
printf(
    "Done. added=%d updated=%d unchanged=%d deleted=%d embedded=%d | in DB: %d products, %d with embeddings\n",
    $stats['added'], $stats['updated'], $stats['unchanged'], $stats['deleted'], $stats['embedded'], $total, $withEmbedding
);
