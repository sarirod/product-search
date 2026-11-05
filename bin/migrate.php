<?php
declare(strict_types=1);

/**
 * Creates (or updates) the database schema. Safe to run any number of times.
 * Usage: php bin/migrate.php
 */

require __DIR__ . '/../src/bootstrap.php';

$db = Db::connect();

$statements = [
    'CREATE EXTENSION IF NOT EXISTS vector',

    // One row per catalog product. *_norm columns hold normalized text for keyword search.
    "CREATE TABLE IF NOT EXISTS products (
        id           text PRIMARY KEY,
        name         text NOT NULL,
        brand        text NOT NULL DEFAULT '',
        category     text NOT NULL,
        subcategory  text NOT NULL,
        description  text NOT NULL,
        attributes   text NOT NULL DEFAULT '',
        image_url    text NOT NULL,
        price        numeric(10,2) NOT NULL CHECK (price > 0),
        needs        text NOT NULL DEFAULT '',
        name_norm    text NOT NULL,
        brand_norm   text NOT NULL,
        cat_norm     text NOT NULL,
        needs_norm   text NOT NULL,
        desc_norm    text NOT NULL,
        content_hash text NOT NULL,
        embedding    vector(768),
        updated_at   timestamptz NOT NULL DEFAULT now()
    )",

    // Cache of query embeddings: a repeated search costs one indexed lookup instead of an API call.
    'CREATE TABLE IF NOT EXISTS query_embeddings (
        model      text NOT NULL,
        query_norm text NOT NULL,
        embedding  vector(768) NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY (model, query_norm)
    )',
];

foreach ($statements as $sql) {
    $db->exec($sql);
}

$version = $db->query("SELECT extversion FROM pg_extension WHERE extname = 'vector'")->fetchColumn();
echo "Schema is up to date (pgvector $version).\n";
