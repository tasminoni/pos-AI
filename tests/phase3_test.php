<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/ChatReportService.php';

function check3(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

final class FakeReportClient implements ReportQueryClient
{
    public array $plans = [];
    public array $response = [];
    public function run(array $plan): array
    {
        $this->plans[] = $plan;
        $response = $this->response;
        $response['date_scope'] = ['start' => $plan['date_start'],
            'end_exclusive' => $plan['date_end_exclusive'],
            'all_authorized_history' => $plan['date_start'] === null];
        $response['next_offset'] = ($response['truncated'] ?? false)
            ? $plan['offset'] + ($response['row_count'] ?? 0) : null;
        return $response;
    }
}

$root = dirname(__DIR__);
$catalog = json_decode((string)file_get_contents($root . '/schema_catalog.json'), true, 64, JSON_THROW_ON_ERROR);
$rules = json_decode((string)file_get_contents($root . '/business_rules.json'), true, 64, JSON_THROW_ON_ERROR);
unset($rules['definitions']['returns']['mode']);
foreach (['opdoheader', 'opdodetail'] as $name) {
    $catalog['tables'][$name]['metadata_status'] = 'observed';
    $catalog['tables'][$name]['schema_name'] = 'dbo';
    foreach ($catalog['tables'][$name]['columns'] as &$column) { $column['metadata_status'] = 'observed'; }
    unset($column);
}
$catalog['tables']['opdoheader']['columns']['xnetamt']['mssql_type'] = 'decimal';
$catalog['tables']['opdoheader']['columns']['xworkingdate']['mssql_type'] = 'date';
$catalog['tables']['opdodetail']['columns']['xnetamt']['mssql_type'] = 'decimal';
$catalog['tables']['opdodetail']['columns']['xqty']['mssql_type'] = 'decimal';
foreach ($rules['required_for_sales_sql'] as $name) { $rules['definitions'][$name]['status'] = 'verified'; }
$rules['definitions']['invoice_status']['included_values'] = ['CONFIRMED'];
$rules['definitions']['returns']['sale_type_values'] = ['SALE'];
$rules['definitions']['working_date']['timezone'] = 'Asia/Dhaka';
$rules['definitions']['product_sales_amount']['status'] = 'verified';
$rules['joins']['header_detail']['status'] = 'verified';
$file = tempnam(sys_get_temp_dir(), 'report_catalog_');
if ($file === false) { throw new RuntimeException('Temporary fixture unavailable.'); }
file_put_contents($file, json_encode($catalog, JSON_THROW_ON_ERROR));
$schema = new SchemaService($file);
unlink($file);

$subset = $schema->verifiedForQuestion('product sales');
check3(isset($subset['tables']['opdoheader'], $subset['tables']['opdodetail'])
    && !isset($subset['tables']['xusers']), 'Verified subset exposed an unauthorized table.');
check3(!isset($subset['tables']['opdoheader']['columns']['xnetamt']['description']),
    'Unverified description was sent to model.');

session_start();
$_SESSION['reporting_identity'] = ['authenticated' => true, 'user_id' => 'test-user',
    'zid' => 777, 'allowed_shopnos' => ['A', 'B']];
$scope = ReportScope::fromSession();
$secret = str_repeat('s', 40);
$intent = [];
$prompts = [];
$insightOutput = null;
$transport = static function (string $url, array $headers, string $body) use (&$intent, &$prompts, &$insightOutput): array {
    $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    check3(!isset($request['generationConfig']['responseSchema']['additionalProperties']),
        'Gemini Schema transport does not accept additionalProperties.');
    foreach ($request['generationConfig']['responseSchema']['properties'] as $property) {
        check3(!in_array('', $property['enum'] ?? [], true), 'Gemini enum values cannot be empty.');
    }
    $prompt = json_decode($request['contents'][0]['parts'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    $prompts[] = $prompt;
    check3(!str_contains($body, '777') && !str_contains($body, 'CONFIRMED'),
        'Scope or sensitive rule values entered Gemini prompt.');
    check3(!str_contains($body, 'xusers'), 'Unauthorized schema entered Gemini prompt.');
    if (isset($prompt['verified_first_to_last_direction'])) {
        $output = $insightOutput;
    } else {
        $output = $intent;
    }
    return ['status' => 200, 'body' => json_encode(['candidates' => [
        ['content' => ['parts' => [['text' => json_encode($output, JSON_THROW_ON_ERROR)]]]],
    ]], JSON_THROW_ON_ERROR)];
};
$gemini = new GeminiService(['api_key' => 'test-key', 'model' => 'gemini-2.5-flash',
    'api_url' => 'https://generativelanguage.googleapis.com/v1beta/models'], $schema, $rules, $transport);
$query = new FakeReportClient();
$service = new ChatReportService($gemini, $query, $schema, $rules, ['max_rows' => 3]);
$now = new DateTimeImmutable('2026-10-13 12:00:00', new DateTimeZone('Asia/Dhaka'));
$baseIntent = ['status' => 'ready', 'clarification_question' => '', 'report' => 'sales_amount',
    'group_by' => 'year', 'date_mode' => 'last_n_years', 'date_start' => '',
    'date_end_exclusive' => '', 'year_count' => '5', 'chart' => 'line', 'language' => 'en'];
$intent = $baseIntent;
$query->response = ['request_id' => 'query-1', 'status' => 'ok',
    'columns' => [['name' => 'year', 'type' => 'integer'], ['name' => 'sales_amount', 'type' => 'decimal']],
    'rows' => [['year' => 2022, 'sales_amount' => '100.00'], ['year' => 2023, 'sales_amount' => '125.50']],
    'row_count' => 2, 'scope' => ['zid' => 777, 'shopnos' => ['A', 'B']],
    'date_scope' => [], 'truncated' => true, 'next_offset' => 2, 'error' => null];
$first = $service->handle('Show five year sales', null, $scope, false, $now);
check3($first['response']['status'] === 'ok' && $first['response']['chart']['type'] === 'line',
    'Five-year report was not presented.');
check3($query->plans[0]['date_start'] === '2022-01-01'
    && $query->plans[0]['date_end_exclusive'] === '2026-10-14', 'Asia/Dhaka five-year window is wrong.');
check3($first['response']['kpis'] === [] && $first['response']['pagination']['next_offset'] === 2,
    'Partial grouped page invented a total or lost pagination.');
check3($first['response']['table']['export_ready'] === true, 'Table is not export-ready.');

$intent = array_replace($baseIntent, ['report' => 'previous', 'group_by' => 'month',
    'date_mode' => 'previous', 'year_count' => '0']);
$query->response['columns'] = [['name' => 'year', 'type' => 'integer'],
    ['name' => 'month', 'type' => 'integer'], ['name' => 'sales_amount', 'type' => 'decimal']];
$query->response['rows'] = [['year' => 2022, 'month' => 1, 'sales_amount' => '10.00']];
$query->response['row_count'] = 1;
$second = $service->handle('What about monthly?', $first['context'], $scope, false, $now);
check3($query->plans[1]['report'] === 'sales_amount' && $query->plans[1]['group_by'] === 'month'
    && $query->plans[1]['date_start'] === '2022-01-01', 'Follow-up context was not applied.');
check3($second['response']['chart']['x'] === 'period'
    && $second['response']['chart']['data'][0]['period'] === '2022-01',
    'Monthly chart lost the year in its X axis.');
check3(count($prompts) === 2 && ($prompts[1]['prior_report']['report'] ?? null) === 'sales_amount',
    'Capped prior context was not sent to planner.');

$beforeCalls = count($prompts);
$query->response['columns'] = [['name' => 'year', 'type' => 'integer'], ['name' => 'sales_amount', 'type' => 'decimal']];
$query->response['rows'] = [['year' => 2024, 'sales_amount' => '40.00']];
$page = $service->handle('', $first['context'], $scope, true, $now);
check3(count($prompts) === $beforeCalls && $query->plans[2]['offset'] === 2
    && $page['response']['metadata']['model_calls'] === 0, 'Pagination called Gemini or changed offset.');

$intent = array_replace($baseIntent, ['report' => 'product_sales_amount', 'group_by' => 'product',
    'date_mode' => 'this_month', 'year_count' => '0', 'chart' => 'bar']);
$query->response['columns'] = [['name' => 'product', 'type' => 'string'],
    ['name' => 'sales_amount', 'type' => 'decimal']];
$query->response['rows'] = [['product' => 'SKU-1', 'sales_amount' => '12.25']];
$query->response['row_count'] = 1;
$query->response['truncated'] = false;
$query->response['next_offset'] = null;
$product = $service->handle('Product wise sales this month', null, $scope, false, $now);
check3($product['response']['chart']['type'] === 'bar'
    && $query->plans[3]['date_start'] === '2026-10-01'
    && $query->plans[3]['date_end_exclusive'] === '2026-10-14', 'Product report or month scope is wrong.');
$compiled = (new SqlValidator($catalog, $rules))->validateAndCompile($query->plans[3], $scope);
check3(str_contains($compiled['sql'], 'INNER JOIN [dbo].[opdodetail]')
    && str_contains($compiled['sql'], '[d].[zid] = ?')
    && substr_count($compiled['sql'], '?') === count($compiled['params']),
    'Product query lost join scoping or binding.');

$query->response['rows'] = [];
$query->response['row_count'] = 0;
$empty = $service->handle('Product sales this month', null, $scope, false, $now);
check3($empty['response']['status'] === 'no_data' && $empty['response']['kpis'] === [],
    'No-data report was not handled.');
$query->response['status'] = 'error';
$query->response['error'] = ['code' => 'QUERY_TIMEOUT', 'message' => 'hidden SQL error'];
$timeout = $service->handle('Product sales this month', null, $scope, false, $now);
check3($timeout['response']['error']['code'] === 'QUERY_TIMEOUT'
    && !str_contains(json_encode($timeout['response'], JSON_THROW_ON_ERROR), 'hidden SQL error'),
    'SQL error was exposed to user.');

$query->response['status'] = 'ok';
$query->response['error'] = null;
$query->response['columns'] = [['name' => 'shop', 'type' => 'string'],
    ['name' => 'sales_amount', 'type' => 'decimal']];
$query->response['rows'] = [['shop' => 'A', 'sales_amount' => '25.00']];
$query->response['row_count'] = 1;
$intent = array_replace($baseIntent, ['report' => 'sales_amount', 'group_by' => 'shop',
    'date_mode' => 'last_year', 'year_count' => '0', 'language' => 'bn', 'chart' => 'bar']);
$shop = $service->handle('গত বছরের দোকান অনুযায়ী বিক্রি', null, $scope, false, $now);
check3($shop['response']['chart']['type'] === 'bar'
    && $query->plans[array_key_last($query->plans)]['date_start'] === '2025-01-01'
    && $query->plans[array_key_last($query->plans)]['date_end_exclusive'] === '2026-01-01'
    && str_contains($shop['response']['assistant_text'], 'রিপোর্ট'), 'Bangla shop report failed.');

$query->response['columns'] = [['name' => 'day', 'type' => 'date'],
    ['name' => 'sales_amount', 'type' => 'decimal']];
$query->response['rows'] = [['day' => '2026-10-01', 'sales_amount' => '5.00']];
$intent = array_replace($baseIntent, ['group_by' => 'day', 'date_mode' => 'explicit',
    'date_start' => '2026-10-01', 'date_end_exclusive' => '2026-10-03',
    'year_count' => '0', 'language' => 'banglish']);
$daily = $service->handle('October 1 theke 2 daily sales', null, $scope, false, $now);
check3($daily['response']['chart']['type'] === 'line'
    && $query->plans[array_key_last($query->plans)]['date_end_exclusive'] === '2026-10-03'
    && str_contains($daily['response']['assistant_text'], 'folafol'), 'Banglish daily report failed.');

$badScope = $query->response;
$badScope['scope'] = ['zid' => 999, 'shopnos' => ['OTHER']];
$query->response = $badScope;
$invalid = $service->handle('October daily sales', null, $scope, false, $now);
check3($invalid['response']['error']['code'] === 'INVALID_RESULT', 'Mismatched query scope was accepted.');
$query->response['scope'] = ['zid' => 777, 'shopnos' => ['A', 'B']];

$intent = array_replace($baseIntent, ['status' => 'clarification',
    'clarification_question' => 'Which period should I use?', 'report' => '']);
$beforeQueries = count($query->plans);
$clarify = $service->handle('Sales please', null, $scope, false, $now);
check3($clarify['response']['status'] === 'clarification' && count($query->plans) === $beforeQueries,
    'Ambiguous question reached Query API.');
$priorPromptCount = count($prompts);
$sensitive = $service->handle('Sales for alice@example.com', null, $scope, false, $now);
check3($sensitive['response']['status'] === 'clarification' && count($prompts) === $priorPromptCount,
    'Sensitive question reached Gemini.');
$oneShop = $service->handle('Sales for shop A last year', null, $scope, false, $now);
check3($oneShop['response']['status'] === 'clarification' && count($prompts) === $priorPromptCount,
    'Unsupported one-shop filter was silently ignored.');
$unsupported = $service->handle('Customer profit last year', null, $scope, false, $now);
check3($unsupported['response']['status'] === 'clarification' && count($prompts) === $priorPromptCount,
    'Unverified business metric reached Gemini.');

$intent = array_replace($baseIntent, ['report' => 'sales_amount', 'group_by' => 'shop',
    'date_mode' => 'last_year', 'year_count' => '0', 'chart' => 'bar']);
$query->response['columns'] = [['name' => 'shop', 'type' => 'string'],
    ['name' => 'sales_amount', 'type' => 'decimal']];
$query->response['rows'] = [['shop' => 'B', 'sales_amount' => '7.00']];
$query->response['row_count'] = 1;
$query->response['scope'] = ['zid' => 777, 'shopnos' => ['B']];
$filtered = $service->handle('Sales by shop B', null, $scope, false, $now,
    ['shopno' => 'B', 'date_start' => '2026-04-01', 'date_end_inclusive' => '2026-04-30']);
$filteredPlan = $query->plans[array_key_last($query->plans)];
check3($filtered['response']['status'] === 'ok' && $filteredPlan['shop_filter'] === 'B'
    && $filteredPlan['date_start'] === '2026-04-01'
    && $filteredPlan['date_end_exclusive'] === '2026-05-01'
    && $filtered['response']['filters']['selected_shopno'] === 'B',
    'Authorized UI shop/date filters were not applied.');
$beforeQueries = count($query->plans);
$invalidShop = $service->handle('Sales by shop Z', null, $scope, false, $now, ['shopno' => 'Z']);
check3($invalidShop['response']['status'] === 'clarification' && count($query->plans) === $beforeQueries,
    'Cross-shop UI filter reached Query API.');

$insightOutput = ['insight' => 'Sales ended higher than at the start of the selected periods.',
    'recommendation' => 'Review period-level activity to understand the change.'];
$query->response['scope'] = ['zid' => 777, 'shopnos' => ['A', 'B']];
$query->response['columns'] = [['name' => 'year', 'type' => 'integer'],
    ['name' => 'sales_amount', 'type' => 'decimal']];
$query->response['rows'] = [['year' => 2025, 'sales_amount' => '9007199254740992.00'],
    ['year' => 2026, 'sales_amount' => '9007199254740992.01']];
$query->response['row_count'] = 2;
$query->response['truncated'] = false;
$intent = $baseIntent;
$insightService = new ChatReportService($gemini, $query, $schema, $rules, ['max_rows' => 3], true);
$insightReport = $insightService->handle('Yearly sales for five years', null, $scope, false, $now);
check3($insightReport['response']['metadata']['model_calls'] === 2
    && count($insightReport['response']['insights']) === 2
    && $prompts[array_key_last($prompts)]['verified_first_to_last_direction'] === 'higher',
    'Safe aggregate insight was not generated from exact comparison.');
check3(!str_contains(json_encode($prompts[array_key_last($prompts)], JSON_THROW_ON_ERROR), '9007199254740992'),
    'Aggregate values were sent to the explanation model.');
$insightOutput['insight'] = 'Sales rose by 20 percent.';
$rejectedInsight = $insightService->handle('Yearly sales for five years', null, $scope, false, $now);
check3($rejectedInsight['response']['insights'] === [], 'Invented numeric claim was displayed.');

$client = new QueryApiClient(['url' => 'http://127.0.0.1/api/query.php', 'token' => $secret],
    static function (string $url, array $headers, string $body): array {
        $map = [];
        foreach ($headers as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $map[$name] = $value;
        }
        check3(isset($map['Cookie']) && str_contains($map['Cookie'], session_id()),
            'Authenticated session cookie missing from internal request.');
        InternalQueryAuth::verifySignature($body, $map, str_repeat('s', 40));
        return ['status' => 200, 'body' => json_encode(['status' => 'ok', 'columns' => [],
            'rows' => [], 'row_count' => 0, 'truncated' => false, 'error' => null], JSON_THROW_ON_ERROR)];
    });
check3($client->run($query->plans[0])['status'] === 'ok', 'Signed query client failed.');
$nestedClient = new QueryApiClient(['url' => 'https://internal.example.test/pos/api/query.php',
    'allowed_hosts' => 'internal.example.test', 'token' => $secret],
    static fn(string $url, array $headers, string $body): array =>
        ['status' => 200, 'body' => json_encode(['status' => 'ok', 'columns' => [],
            'rows' => [], 'row_count' => 0, 'truncated' => false, 'error' => null], JSON_THROW_ON_ERROR)]);
check3($nestedClient->run($query->plans[0])['status'] === 'ok', 'Private subdirectory endpoint was rejected.');
try {
    (new QueryApiClient(['url' => 'http://example.com/api/query.php', 'token' => $secret]))->run($query->plans[0]);
    throw new RuntimeException('Unsafe internal URL accepted.');
} catch (RuntimeException $error) {
    check3($error->getMessage() !== 'Unsafe internal URL accepted.', 'Unsafe internal URL accepted.');
}

$retryCalls = 0;
$retryGemini = new GeminiService(['api_key' => 'test-key', 'model' => 'gemini-2.5-flash',
    'api_url' => 'https://generativelanguage.googleapis.com/v1beta/models'], $schema, $rules,
    static function () use (&$retryCalls, $baseIntent): array {
        $retryCalls++;
        return $retryCalls === 1 ? ['status' => 503, 'body' => '{}'] :
            ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($baseIntent)]]]]]])];
    });
check3($retryGemini->planChat('what is sale for today?', null, ['sales_amount'], '2026-10-13')['status'] === 'ready'
    && $retryCalls === 2, 'Transient AI failures must retry without replacing AI planning with phrase shortcuts.');
echo "Phase 3 tests passed.\n";
