<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/InternalQueryAuth.php';
require_once __DIR__ . '/../services/QueryExecutor.php';
require_once __DIR__ . '/../services/GeneratedSqlExecutor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function queryApiReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function queryApiError(int $status, string $code): never
{
    queryApiReply($status, [
        'request_id' => bin2hex(random_bytes(8)), 'status' => 'error',
        'columns' => [], 'rows' => [], 'row_count' => 0,
        'scope' => null, 'date_scope' => null, 'truncated' => false, 'next_offset' => null,
        'error' => ['code' => $code, 'message' => 'Query request could not be completed.'],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    queryApiError(405, 'METHOD_NOT_ALLOWED');
}
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    queryApiError(415, 'UNSUPPORTED_MEDIA_TYPE');
}

try {
    $authenticated = false;
    $config = require __DIR__ . '/../config.php';
    if (!InternalQueryAuth::isAllowedPeer((string)($_SERVER['REMOTE_ADDR'] ?? ''),
        (string)($config['query_api']['allowed_ips'] ?? '127.0.0.1,::1'))) {
        queryApiError(403, 'FORBIDDEN');
    }
    $maxBody = max(512, min(65536, (int)($config['query_api']['max_body_bytes'] ?? 8192)));
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
        queryApiError(413, 'REQUEST_TOO_LARGE');
    }
    $body = file_get_contents('php://input', false, null, 0, $maxBody + 1);
    if (!is_string($body) || strlen($body) > $maxBody || $body === '') {
        queryApiError(413, 'REQUEST_TOO_LARGE');
    }
    $headers = [
        'X-Query-Timestamp' => $_SERVER['HTTP_X_QUERY_TIMESTAMP'] ?? '',
        'X-Query-Nonce' => $_SERVER['HTTP_X_QUERY_NONCE'] ?? '',
        'X-Query-Signature' => $_SERVER['HTTP_X_QUERY_SIGNATURE'] ?? '',
    ];
    $window = (int)($config['query_api']['signature_window_seconds'] ?? 60);
    $nonce = InternalQueryAuth::verifySignature($body, $headers, (string)($config['query_api']['token'] ?? ''), $window);
    if (session_status() !== PHP_SESSION_ACTIVE && @session_start() !== true) {
        queryApiError(401, 'UNAUTHORIZED');
    }
    $scope = ReportScope::forAccess($config);
    if (!is_array($_SESSION['_query_nonce_cache'] ?? null)) {
        $_SESSION['_query_nonce_cache'] = [];
    }
    InternalQueryAuth::claimNonce($nonce, $_SESSION['_query_nonce_cache'], $window);
    session_write_close();
    $authenticated = true;

    try {
        $envelope = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        queryApiError(400, 'INVALID_REQUEST');
    }
    if (is_array($envelope) && in_array(array_keys($envelope), [['sql','filters'],['sql','filters','offset']],true)) {
        if (empty($config['reporting_ui']['generated_sql_enabled']) || !is_string($envelope['sql']) || !is_array($envelope['filters'])
            || array_diff(array_keys($envelope['filters']), ['shopno','date_start','date_end_inclusive'])) { queryApiError(400,'INVALID_REQUEST'); }
        $offset=$envelope['offset']??0;
        if (!is_int($offset)) { queryApiError(400,'INVALID_REQUEST'); }
        if (($envelope['filters']['shopno'] ?? null) !== null) { $scope = $scope->forShop($envelope['filters']['shopno']); }
        $sqlSchema=json_decode((string)file_get_contents(__DIR__.'/../sql_schema.json'),true,512,JSON_THROW_ON_ERROR);
        $executor=new GeneratedSqlExecutor(new GeneratedSqlValidator($sqlSchema,(int)$config['query_limits']['max_rows']),
            new SqlsrvQueryDriver($config['query_db'],$config['db']),$config['query_limits']);
        $result=$executor->execute($envelope['sql'],$scope,$envelope['filters'],$offset);
        $httpStatus=match($result['error']['code']??null){null=>200,'PLAN_REJECTED'=>422,'QUERY_TIMEOUT'=>504,'RESULT_TOO_LARGE'=>413,default=>503};
        queryApiReply($httpStatus,$result);
    }
    if (!is_array($envelope) || array_keys($envelope) !== ['plan']
        || !is_array($envelope['plan']) || array_is_list($envelope['plan'])) {
        queryApiError(400, 'INVALID_REQUEST');
    }
    $catalogText = @file_get_contents(__DIR__ . '/../schema_catalog.json');
    $rulesText = @file_get_contents(__DIR__ . '/../business_rules.json');
    if (!is_string($catalogText) || !is_string($rulesText)) {
        queryApiError(503, 'QUERY_UNAVAILABLE');
    }
    $catalog = json_decode($catalogText, true, 512, JSON_THROW_ON_ERROR);
    $rules = json_decode($rulesText, true, 512, JSON_THROW_ON_ERROR);
    $validator = new SqlValidator($catalog, $rules, $config['query_limits'] ?? []);
    $driver = new SqlsrvQueryDriver($config['query_db'] ?? [], $config['db'] ?? []);
    $executor = new QueryExecutor($validator, $driver, $config['query_limits'] ?? []);
    $result = $executor->execute($envelope['plan'], $scope);
    $code = $result['error']['code'] ?? null;
    $status = match ($code) {
        null => 200,
        'PLAN_REJECTED' => 422,
        'QUERY_TIMEOUT' => 504,
        'RESULT_TOO_LARGE' => 413,
        default => 503,
    };
    queryApiReply($status, $result);
} catch (JsonException) {
    queryApiError($authenticated ? 503 : 400, $authenticated ? 'QUERY_UNAVAILABLE' : 'INVALID_REQUEST');
} catch (Throwable) {
    // No SQLSRV messages, credentials, SQL text, or signed body leave this route.
    queryApiError(($authenticated ?? false) ? 503 : 401, ($authenticated ?? false) ? 'QUERY_UNAVAILABLE' : 'UNAUTHORIZED');
}
