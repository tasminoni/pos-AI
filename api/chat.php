<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/ChatReportService.php';
require_once __DIR__ . '/../services/GeneratedReportService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function chatReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function chatError(int $status, string $code, string $message): never
{
    chatReply($status, ['status' => 'error', 'assistant_text' => $message,
        'error' => ['code' => $code, 'message' => $message], 'kpis' => [],
        'table' => ['columns' => [], 'rows' => [], 'row_count' => 0, 'export_ready' => false],
        'chart' => ['type' => 'none', 'x' => null, 'y' => null, 'data' => []],
        'filters' => null, 'time_range' => null,
        'pagination' => ['page_size' => 0, 'offset' => 0, 'truncated' => false, 'next_offset' => null],
        'metadata' => ['report' => null, 'group_by' => null, 'query_request_id' => null],
        'conversation_id' => null]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    chatError(405, 'METHOD_NOT_ALLOWED', 'Use GET to start a reporting session or POST to ask a question.');
}

try {
    $config = require __DIR__ . '/../config.php';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    if (session_status() !== PHP_SESSION_ACTIVE && @session_start() !== true) {
        chatError(401, 'UNAUTHORIZED', 'Sign in to use reporting.');
    }
    try { $scope = ReportScope::forAccess($config); }
    catch (Throwable) { chatError(401, 'UNAUTHORIZED', 'Sign in to use reporting.'); }
    if (!is_string($_SESSION['_chat_csrf'] ?? null)) {
        $_SESSION['_chat_csrf'] = bin2hex(random_bytes(32));
    }
    if ($method === 'GET') {
        $history = [];
        foreach ($_SESSION['_report_conversations'] ?? [] as $conversationId => $entry) {
            if (($entry['identity'] ?? null) !== [$scope->userId, $scope->zid, $scope->shopnos]) { continue; }
            $history[] = ['conversation_id' => $conversationId,
                'updated_at' => $entry['updated_at'] ?? null,
                'turns' => $entry['turns'] ?? []];
        }
        chatReply(200, ['status' => 'ready', 'csrf_token' => $_SESSION['_chat_csrf'],
            'authorized_shopnos' => $scope->shopnos,
            'request' => ['action' => 'ask', 'question' => 'Show yearly sales for the last five years',
                'conversation_id' => null], 'max_question_bytes' => 1200, 'history' => $history]);
    }
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['_chat_csrf'], $csrf)) {
        chatError(403, 'CSRF_REJECTED', 'Refresh the reporting session and retry.');
    }
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        chatError(415, 'UNSUPPORTED_MEDIA_TYPE', 'Send a JSON request.');
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        chatError(413, 'REQUEST_TOO_LARGE', 'The request is too large.');
    }
    $body = file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($body) || $body === '' || strlen($body) > 4096) {
        chatError(413, 'REQUEST_TOO_LARGE', 'The request is too large.');
    }
    try {
        $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        chatError(400, 'INVALID_REQUEST', 'The request JSON is invalid.');
    }
    if (!is_array($request) || array_is_list($request)) {
        chatError(400, 'INVALID_REQUEST', 'The request format is invalid.');
    }
    $action = $request['action'] ?? null;
    $question = $request['question'] ?? '';
    $id = $request['conversation_id'] ?? null;
    $filters = $request['filters'] ?? [];
    if (!is_array($filters) || (array_is_list($filters) && $filters !== [])
        || array_diff(array_keys($filters), ['date_start', 'date_end_inclusive', 'shopno'])
        || (array_key_exists('shopno', $filters) && $filters['shopno'] !== null
            && !is_string($filters['shopno']))
        || (array_key_exists('date_start', $filters) !== array_key_exists('date_end_inclusive', $filters))) {
        chatError(400, 'INVALID_FILTERS', 'Choose valid reporting filters.');
    }
    if (!in_array($action, ['ask', 'page'], true) || !is_string($question)
        || ($action === 'ask' && ($question === '' || strlen($question) > 1200))
        || ($action === 'page' && $question !== '')
        || ($id !== null && (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)))
        || ($action === 'page' && $filters !== [])
        || array_diff(array_keys($request), ['action', 'question', 'conversation_id', 'filters'])) {
        chatError(400, 'INVALID_REQUEST', 'The request format is invalid.');
    }
    if (!is_array($_SESSION['_report_conversations'] ?? null)) {
        $_SESSION['_report_conversations'] = [];
    }
    if ($id === null) {
        if ($action === 'page') { chatError(400, 'INVALID_REQUEST', 'A conversation is required for another page.'); }
        $id = bin2hex(random_bytes(16));
    } elseif (!isset($_SESSION['_report_conversations'][$id])) {
        chatError(404, 'CONVERSATION_NOT_FOUND', 'This reporting conversation is unavailable.');
    } elseif (($_SESSION['_report_conversations'][$id]['identity'] ?? null)
        !== [$scope->userId, $scope->zid, $scope->shopnos]) {
        chatError(404, 'CONVERSATION_NOT_FOUND', 'This reporting conversation is unavailable.');
    }
    $prior = $_SESSION['_report_conversations'][$id]['context'] ?? null;
    $identity = [$scope->userId, $scope->zid, $scope->shopnos];
    session_write_close(); // The internal Query API opens the same authenticated session.

    $config = require __DIR__ . '/../config.php';
    $schema = new SchemaService(__DIR__ . '/../schema_catalog.json');
    $rules = json_decode((string)file_get_contents(__DIR__ . '/../business_rules.json'), true, 64, JSON_THROW_ON_ERROR);
    $service = new ChatReportService(new GeminiService($config['gemini'], $schema, $rules),
        new QueryApiClient($config['query_api']), $schema, $rules, $config['query_limits'] ?? [],
        (bool)($config['reporting_ui']['insights_enabled'] ?? false));
    if (!empty($config['reporting_ui']['generated_sql_enabled'])) {
        $sqlSchema=json_decode((string)file_get_contents(__DIR__.'/../sql_schema.json'),true,512,JSON_THROW_ON_ERROR);
        $service=new GeneratedReportService(new GeminiService($config['gemini'],$schema,$rules),
            new QueryApiClient($config['query_api']),$sqlSchema,$config['query_limits']);
    }
    $handled = $service->handle($question, $prior, $scope, $action === 'page', null, $filters);

    if (@session_start() !== true) {
        chatError(401, 'UNAUTHORIZED', 'Sign in to use reporting.');
    }
    try { $after = ReportScope::forAccess($config); }
    catch (Throwable) { chatError(401, 'UNAUTHORIZED', 'Sign in to use reporting.'); }
    if ([$after->userId, $after->zid, $after->shopnos] !== $identity) {
        chatError(401, 'UNAUTHORIZED', 'The reporting session changed. Please retry.');
    }
    if (!is_array($_SESSION['_report_conversations'] ?? null)) {
        $_SESSION['_report_conversations'] = [];
    }
    $entry = $_SESSION['_report_conversations'][$id] ?? ['turns' => []];
    $entry['identity'] = $identity;
    $entry['context'] = $handled['context'];
    if (isset($handled['context']['cache_key'], $handled['context']['cache_plan'])) {
        $_SESSION['_sql_generation_cache'][$handled['context']['cache_key']]=['plan'=>$handled['context']['cache_plan'],'expires'=>time()+600];
        $_SESSION['_sql_generation_cache']=array_slice($_SESSION['_sql_generation_cache'],-16,null,true);
        unset($entry['context']['cache_key'],$entry['context']['cache_plan']);
    }
    $entry['updated_at'] = time();
    if ($action === 'ask') {
        $entry['turns'][] = ['status' => $handled['response']['status'],
            'report' => $handled['context']['report'] ?? null,
            'group_by' => $handled['context']['group_by'] ?? null,
            'date_start' => $handled['context']['date_start'] ?? null,
            'date_end_exclusive' => $handled['context']['date_end_exclusive'] ?? null,
            'at' => gmdate('c')];
        $entry['turns'] = array_slice($entry['turns'], -12);
    }
    $_SESSION['_report_conversations'][$id] = $entry;
    uasort($_SESSION['_report_conversations'], static fn(array $a, array $b): int =>
        ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));
    $_SESSION['_report_conversations'] = array_slice($_SESSION['_report_conversations'], 0, 8, true);
    session_write_close();
    $response = $handled['response'];
    $response['conversation_id'] = $id;
    chatReply($response['status'] === 'error' ? 503 : 200, $response);
} catch (JsonException) {
    chatError(503, 'REPORTING_UNAVAILABLE', 'Reporting is unavailable. Please retry.');
} catch (Throwable) {
    chatError(503, 'REPORTING_UNAVAILABLE', 'Reporting is unavailable. Please retry.');
}
