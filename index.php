<?php
declare(strict_types=1);

// If requested from browser without JSON accept, redirect to UI
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (stripos($accept, 'text/html') !== false && !isset($_GET['api'])) {
    header('Location: ui/index.html');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
$databaseConnected = false;
try {
    $probe = DatabaseManager::queryOne('SELECT 1 AS connection_ok');
    $databaseConnected = ($probe['connection_ok'] ?? null) === 1;
} catch (Throwable $error) {
    error_log('Application database health check failed: ' . $error->getMessage());
}

echo json_encode([
    'module' => 'Mobile POS AI Conversational Assistant',
    'status' => $databaseConnected ? 'active' : 'degraded',
    'database_connected' => $databaseConnected,
    'version' => '1.0.0',
    'php_version' => PHP_VERSION,
    'sqlsrv_driver_loaded' => function_exists('sqlsrv_connect'),
    'endpoints' => [
        'chat' => 'api/chat.php',
        'query' => 'api/query.php',
        'web_ui' => 'ui/index.html',
    ],
    'active_provider' => $config['provider'] ?? 'gemini',
    'gemini_configured' => !empty($config['gemini']['api_key']) && $config['gemini']['api_key'] !== 'your_gemini_api_key_here',
    'openai_configured' => !empty($config['openai']['api_key']) && $config['openai']['api_key'] !== 'your_openai_api_key_here',
    'database_target' => $config['db']['server'] . ' / ' . $config['db']['database'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
