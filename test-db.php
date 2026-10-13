<?php
declare(strict_types=1);

// Local diagnostic only; do not expose database diagnostics remotely.
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $result = DatabaseManager::queryOne('SELECT DB_NAME() AS database_name, 1 AS connection_ok');
    if (($result['connection_ok'] ?? null) !== 1) {
        throw new RuntimeException('No live database query result was returned.');
    }
    echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Database connection diagnostic: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Check the PHP error log.']);
    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}
