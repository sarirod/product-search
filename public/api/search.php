<?php
declare(strict_types=1);

/**
 * GET /api/search.php?q=<query>
 *
 * 200 {"query","mode":"hybrid"|"keyword","took_ms","count","results":[{id,name,brand,category,subcategory,price,image_url}]}
 * 400 {"error":"empty_query"}      — missing / blank query
 * 405 {"error":"method_not_allowed"}
 * 500 {"error":"server_error"}     — details go to the server log only
 */

require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(405, ['error' => 'method_not_allowed']);
}

$query = $_GET['q'] ?? '';
if (!is_string($query) || trim($query) === '') {
    respond(400, ['error' => 'empty_query']);
}
$query = trim(mb_substr($query, 0, Search::MAX_QUERY_LENGTH));

try {
    $search = new Search(Db::connect(), Embedder::fromEnv());
    respond(200, $search->search($query));
} catch (Throwable $e) {
    error_log('[search] ' . get_class($e) . ': ' . $e->getMessage());
    respond(500, ['error' => 'server_error']);
}
