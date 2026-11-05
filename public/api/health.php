<?php
declare(strict_types=1);

/**
 * GET /api/health.php        -> {"ok":true}            (used by the uptime pinger; does not touch the DB,
 *                                                        so it doesn't burn the free DB compute quota)
 * GET /api/health.php?db=1   -> {"ok":true,"db":true}  (manual check that the database is reachable)
 */

require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$body = ['ok' => true];
if (isset($_GET['db'])) {
    try {
        $products = (int) Db::connect()->query('SELECT count(*) FROM products')->fetchColumn();
        $body += ['db' => true, 'products' => $products];
    } catch (Throwable $e) {
        error_log('[health] ' . $e->getMessage());
        http_response_code(503);
        $body = ['ok' => false, 'db' => false];
    }
}
echo json_encode($body);
