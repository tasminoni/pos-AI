<?php
declare(strict_types=1);

/**
 * Configuration manager for AI-Powered Conversational Assistant (/ai-chat)
 */

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $filePath): array
    {
        $vars = [];
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return $vars;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $vars;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            // Trim enclosing quotes
            $val = trim($val, "\"'");
            $vars[$key] = $val;
            // Also register in environment if not set
            if (!getenv($key)) {
                putenv("$key=$val");
            }
        }
    }

    return $vars;
    }
}

// Load .env if present
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    loadEnvFile($envFile);
}

// Determine default provider: default to 'gemini' if GEMINI_API_KEY is present or AI_PROVIDER is set
$provider = getenv('AI_PROVIDER') ?: (getenv('GEMINI_API_KEY') ? 'gemini' : (getenv('OPENAI_API_KEY') ? 'openai' : 'gemini'));

// Return unified configuration array
return [
    'provider' => $provider,
    'gemini' => [
        'schema_cache_path' => __DIR__ . '/ai_schema_cache.json',
        'api_key' => getenv('GEMINI_API_KEY') ?: '',
        'model' => getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash',
        'api_url' => getenv('GEMINI_API_URL') ?: 'https://generativelanguage.googleapis.com/v1beta/models',
        'temperature' => 0.2,
        'timeout_seconds' => (int)(getenv('GEMINI_TIMEOUT_SECONDS') ?: 25),
        'max_response_bytes' => (int)(getenv('GEMINI_MAX_RESPONSE_BYTES') ?: 65536),
        'max_tokens' => 1500,
    ],
    'openai' => [
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'model' => getenv('OPENAI_MODEL') ?: 'gpt-4o-mini',
        'api_url' => 'https://api.openai.com/v1/chat/completions',
        'temperature' => 0.2,
        'max_tokens' => 1500,
    ],
    'db' => [
        'server' => getenv('DB_SERVER') ?: '',
        'database' => getenv('DB_NAME') ?: '',
        'uid' => getenv('DB_USER') ?: '',
        'pwd' => getenv('DB_PASS') ?: '',
        'charset' => 'UTF-8',
        'trust_cert' => 'Yes',
        'login_timeout_seconds' => (int)(getenv('DB_LOGIN_TIMEOUT_SECONDS') ?: 5),
    ],
    'defaults' => [
        'zid' => (int)(getenv('DEFAULT_ZID') ?: 100070),
        'shopno' => getenv('DEFAULT_SHOPNO') ?: '',
    ],
    'mock_mode' => strtolower((string)(getenv('MOCK_DB_MODE') ?: 'false')) === 'true',
    'query_api' => [
        'url' => getenv('QUERY_API_URL') ?: '',
        'allowed_hosts' => getenv('QUERY_API_ALLOWED_HOSTS') ?: '127.0.0.1,localhost,::1',
        'token' => getenv('QUERY_API_TOKEN') ?: '',
        'signature_window_seconds' => (int)(getenv('QUERY_API_SIGNATURE_WINDOW_SECONDS') ?: 60),
        'max_body_bytes' => (int)(getenv('QUERY_API_MAX_BODY_BYTES') ?: 8192),
        'allowed_ips' => getenv('QUERY_API_ALLOWED_IPS') ?: '127.0.0.1,::1',
    ],
    'query_db' => [
        'use_pos_connection' => strtolower((string)(getenv('QUERY_USE_POS_CONNECTION') ?: 'false')) === 'true',
        'server' => getenv('QUERY_DB_SERVER') ?: '',
        'database' => getenv('QUERY_DB_NAME') ?: '',
        'uid' => getenv('QUERY_DB_USER') ?: '',
        'pwd' => getenv('QUERY_DB_PASS') ?: '',
        'trust_cert' => strtolower((string)(getenv('QUERY_DB_TRUST_CERT') ?: 'false')) === 'true' ? 'Yes' : 'No',
        'login_timeout_seconds' => (int)(getenv('QUERY_DB_LOGIN_TIMEOUT_SECONDS') ?: 5),
    ],
    'query_limits' => [
        'timeout_seconds' => (int)(getenv('QUERY_TIMEOUT_SECONDS') ?: 20),
        'max_rows' => (int)(getenv('QUERY_MAX_ROWS') ?: 100),
        'max_offset' => (int)(getenv('QUERY_MAX_OFFSET') ?: 10000),
        'max_response_bytes' => (int)(getenv('QUERY_MAX_RESPONSE_BYTES') ?: 1048576),
        'max_detail_days' => (int)(getenv('QUERY_MAX_DETAIL_DAYS') ?: 366),
    ],
    'reporting_ui' => [
        'generated_sql_enabled' => strtolower((string)(getenv('AI_GENERATED_SQL') ?: 'false')) === 'true',
        'insights_enabled' => strtolower((string)(getenv('REPORT_INSIGHTS_ENABLED') ?: 'true')) === 'true',
    ],
    'chat_require_session' => strtolower((string)(getenv('CHAT_REQUIRE_SESSION') ?: 'true')) !== 'false',
    'local_preview_mode' => strtolower((string)(getenv('LOCAL_PREVIEW_MODE') ?: 'false')) === 'true',
];
