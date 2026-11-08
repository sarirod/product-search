<?php
declare(strict_types=1);

/**
 * Reads the provided catalog CSV plus our two side files (prices, needs) and
 * produces validated product rows ready for the database.
 *
 * The original catalog file is never modified: prices and "needs" (use-cases)
 * live in separate files and are merged by product id.
 */
final class Catalog
{
    private const CATALOG_COLUMNS = ['id', 'name', 'brand', 'category', 'subcategory', 'description', 'attributes', 'image_url'];

    /**
     * @return list<array<string,mixed>> one row per product
     * @throws RuntimeException with a list of every problem found (fail fast, fail loudly)
     */
    public static function load(string $catalogPath, string $pricesPath, string $needsPath): array
    {
        $catalog = self::readCsv($catalogPath, self::CATALOG_COLUMNS);
        $prices = self::indexById(self::readCsv($pricesPath, ['id', 'price']), 'price');
        $needs = self::indexById(self::readCsv($needsPath, ['id', 'needs']), 'needs');

        $errors = [];
        $rows = [];
        $seen = [];
        foreach ($catalog as $line => $r) {
            $id = $r['id'];
            $where = "catalog line $line ($id)";
            if (!preg_match('/^P\d{4}$/', $id)) {
                $errors[] = "$where: invalid id";
            }
            if (isset($seen[$id])) {
                $errors[] = "$where: duplicate id";
            }
            $seen[$id] = true;
            foreach (['name', 'category', 'subcategory', 'description', 'image_url'] as $required) {
                if ($r[$required] === '') {
                    $errors[] = "$where: empty $required";
                }
            }
            if ($r['image_url'] !== '' && !preg_match('#^https://#', $r['image_url'])) {
                $errors[] = "$where: image_url must be https";
            }
            $price = $prices[$id] ?? null;
            if ($price === null || !is_numeric($price) || (float) $price <= 0) {
                $errors[] = "$where: missing or invalid price";
            }
            $need = $needs[$id] ?? '';
            if ($need === '') {
                $errors[] = "$where: missing needs line";
            }

            $rows[] = $r + ['price' => (float) $price, 'needs' => $need];
        }
        foreach ([$prices, $needs] as $side) {
            foreach (array_keys($side) as $id) {
                if (!isset($seen[$id])) {
                    $errors[] = "side file references unknown product id $id";
                }
            }
        }
        if ($errors) {
            throw new RuntimeException("Catalog validation failed:\n - " . implode("\n - ", $errors));
        }
        return $rows;
    }

    /** The text that represents a product's meaning — this is what gets embedded. */
    public static function embeddingText(array $p): string
    {
        return implode("\n", array_filter([
            'מוצר: ' . $p['name'],
            $p['brand'] !== '' ? 'מותג: ' . $p['brand'] : '',
            'קטגוריה: ' . $p['category'] . ' > ' . $p['subcategory'],
            'תיאור: ' . $p['description'],
            'מאפיינים: ' . $p['attributes'],
            'מתאים ל: ' . $p['needs'],
        ]));
    }

    /**
     * @param list<string> $expectedHeader
     * @return array<int,array<string,string>> keyed by 1-based line number
     */
    private static function readCsv(string $path, array $expectedHeader): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open $path");
        }
        try {
            $header = fgetcsv($fh, null, ',', '"', '');
            if ($header === false) {
                throw new RuntimeException("$path is empty");
            }
            $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // strip UTF-8 BOM
            $header = array_map('trim', $header);
            if ($header !== $expectedHeader) {
                throw new RuntimeException("$path: unexpected header. Expected: " . implode(',', $expectedHeader));
            }
            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($fh, null, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue; // blank line
                }
                if (count($cells) !== count($header)) {
                    throw new RuntimeException("$path line $line: expected " . count($header) . ' columns, got ' . count($cells));
                }
                $rows[$line] = array_combine($header, array_map(static fn ($c): string => trim((string) $c), $cells));
            }
            return $rows;
        } finally {
            fclose($fh);
        }
    }

    /** @return array<string,string> */
    private static function indexById(array $rows, string $valueColumn): array
    {
        $out = [];
        foreach ($rows as $line => $r) {
            if (isset($out[$r['id']])) {
                throw new RuntimeException("Duplicate id {$r['id']} in side file (line $line)");
            }
            $out[$r['id']] = $r[$valueColumn];
        }
        return $out;
    }
}
