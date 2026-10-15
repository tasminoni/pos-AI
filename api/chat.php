<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/GeneratedReportService.php';
require_once __DIR__ . '/../services/ConversationHistory.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function chatReply(int $status, array $payload): never
{
    if ($status >= 400) {
        $code=$payload['error']['code']??'UNKNOWN';
        $reason=$payload['error']['reason']??null;
        error_log('chat_error '.json_encode([
            'http_status'=>$status,
            'code'=>is_string($code)&&preg_match('/^[A-Z_]{1,64}$/D',$code)?$code:'UNKNOWN',
            'reason'=>is_string($reason)&&preg_match('/^[a-z_]{1,64}$/D',$reason)?$reason:null,
            'query_request_id'=>$payload['metadata']['query_request_id']??null,
        ],JSON_THROW_ON_ERROR));
    }
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
    catch (Throwable) { chatError(503, 'REPORTING_CONFIGURATION', 'Check sql_schema.json and the reporting access configuration.'); }
    if (!is_string($_SESSION['_chat_csrf'] ?? null)) {
        $_SESSION['_chat_csrf'] = bin2hex(random_bytes(32));
    }
    if ($method === 'GET') {
        $history = [];
        foreach ($_SESSION['_report_conversations'] ?? [] as $conversationId => $entry) {
            if (($entry['identity'] ?? null) !== [$scope->userId, $scope->zid, $scope->shopnos, $scope->schemaId]) { continue; }
            $history[] = ['conversation_id' => $conversationId,
                'updated_at' => $entry['updated_at'] ?? null,
                'turns' => ConversationHistory::summaries($entry)];
        }
        chatReply(200, ['status' => 'ready', 'csrf_token' => $_SESSION['_chat_csrf'],
            'authorized_shopnos' => $scope->shopnos,
            'request' => ['action' => 'ask', 'question' => 'Show a list of records',
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
    if (!in_array($action, ['ask', 'page', 'restore', 'delete'], true) || !is_string($question)
        || ($action === 'ask' && ($question === '' || strlen($question) > 1200))
        || ($action !== 'ask' && $question !== '')
        || ($id !== null && (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)))
        || ($action !== 'ask' && $filters !== [])
        || array_diff(array_keys($request), ['action', 'question', 'conversation_id', 'filters'])) {
        chatError(400, 'INVALID_REQUEST', 'The request format is invalid.');
    }
    if (!is_array($_SESSION['_report_conversations'] ?? null)) {
        $_SESSION['_report_conversations'] = [];
    }
    if ($id === null) {
        if ($action !== 'ask') { chatError(400, 'INVALID_REQUEST', 'Choose a reporting conversation.'); }
        $id = bin2hex(random_bytes(16));
    } elseif (!isset($_SESSION['_report_conversations'][$id])) {
        chatError(404, 'CONVERSATION_NOT_FOUND', 'This reporting conversation is unavailable.');
    } elseif (($_SESSION['_report_conversations'][$id]['identity'] ?? null)
        !== [$scope->userId, $scope->zid, $scope->shopnos, $scope->schemaId]) {
        chatError(404, 'CONVERSATION_NOT_FOUND', 'This reporting conversation is unavailable.');
    }
    if ($action === 'delete') {
        unset($_SESSION['_report_conversations'][$id]);
        session_write_close();
        chatReply(200, ['status' => 'deleted', 'conversation_id' => $id]);
    }
    if ($action === 'restore') {
        $turns = ConversationHistory::restore($_SESSION['_report_conversations'][$id]);
        session_write_close();
        chatReply(200, ['status' => 'restored', 'conversation_id' => $id, 'turns' => $turns]);
    }
    $prior = $_SESSION['_report_conversations'][$id]['context'] ?? null;
    $identity = [$scope->userId, $scope->zid, $scope->shopnos, $scope->schemaId];
    session_write_close(); // The internal Query API opens the same authenticated session.

    $config = require __DIR__ . '/../config.php';
    $sqlSchema=SqlSchema::load($config['sql_schema_path']);
    $service=new GeneratedReportService(new GeminiService($config['gemini']),
        new QueryApiClient($config['query_api']),$sqlSchema,$config['query_limits']);
    $handled = $service->handle($question, $prior, $scope, $action === 'page', null, $filters);

    if (@session_start() !== true) {
        chatError(401, 'UNAUTHORIZED', 'Sign in to use reporting.');
    }
    try { $after = ReportScope::forAccess($config); }
    catch (Throwable) { chatError(401, 'UNAUTHORIZED', 'Sign in to use reporting.'); }
    if ([$after->userId, $after->zid, $after->shopnos, $after->schemaId] !== $identity) {
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
        $entry = ConversationHistory::append($entry, $question,
            $handled['response'] + ['conversation_id' => $id],
            ['status' => $handled['response']['status'],
            'title' => $handled['response']['metadata']['title'] ?? null,
            'report' => $handled['context']['report'] ?? null,
            'group_by' => $handled['context']['group_by'] ?? null,
            'date_start' => $handled['context']['date_start'] ?? null,
            'date_end_exclusive' => $handled['context']['date_end_exclusive'] ?? null,
            'at' => gmdate('c')]);
    }
    $_SESSION['_report_conversations'][$id] = $entry;
    uasort($_SESSION['_report_conversations'], static fn(array $a, array $b): int =>
        ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));
    $_SESSION['_report_conversations'] = array_slice($_SESSION['_report_conversations'], 0, 8, true);
    session_write_close();
    $response = $handled['response'];
    $response['conversation_id'] = $id;
    $httpStatus = $response['status'] !== 'error' ? 200 : match ($response['error']['code'] ?? '') {
        'PLAN_REJECTED' => 422,
        'AI_TIMEOUT', 'QUERY_TIMEOUT' => 504,
        'AI_RATE_LIMITED' => 429,
        default => 503,
    };
    chatReply($httpStatus, $response);
} catch (JsonException) {
    chatError(503, 'REPORTING_UNAVAILABLE', 'Reporting is unavailable. Please retry.');
} catch (Throwable) {
    chatError(503, 'REPORTING_UNAVAILABLE', 'Reporting is unavailable. Please retry.');
}
