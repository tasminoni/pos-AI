<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/SchemaService.php';
require_once __DIR__ . '/../services/GeminiService.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $action, string $message): void
{
    try {
        $action();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$reference = file_get_contents($root . '/Pos_Local_Database.txt');
check(is_string($reference), 'Reference file missing.');
$parsed = SchemaService::fromReference($reference);
check(count($parsed['tables']) === 11, 'Expected eleven documented tables.');
check(count($parsed['relationships']) >= 4, 'Documented relationships not parsed.');
check(isset($parsed['tables']['opdoheader']['columns']['xnetamt']), 'Sales column not parsed.');
check($parsed['tables']['opdoheader']['columns']['xnetamt']['description_status'] === 'unverified', 'Descriptions must remain unverified.');
check($parsed['tables']['xusers']['columns'] === [], 'Sensitive user columns must be excluded from persisted catalog.');
check(!isset($parsed['tables']['opdoheader']['columns']['xcardno']), 'Card number must be excluded.');

$schema = new SchemaService($root . '/schema_catalog.json');
$salesSubset = $schema->forQuestion('গত ৫ বছরের বিক্রি দেখাও');
check(array_keys($salesSubset['tables']) === ['opdoheader'], 'Bangla sales retrieval should send only the header table.');
check(!isset($salesSubset['tables']['opdoheader']['columns']['xcardamt']), 'Irrelevant payment column sent to Gemini.');
$itemSubset = $schema->forQuestion('product sales by item');
check(count($itemSubset['tables']) === 3, 'Item question should retrieve the three approved tables.');
check(array_keys($schema->forQuestion('pach bochorer bikri')['tables']) === ['opdoheader'], 'Banglish sales retrieval failed.');
check($schema->forQuestion('staff password')['tables'] === [], 'Sensitive question should retrieve no catalog.');

session_start();
fails(static fn() => ReportScope::fromSession(), 'Missing authenticated session was accepted.');
$publicConfig = ['chat_require_session' => false, 'defaults' => ['zid' => 777, 'shopno' => 'PUBLIC-SHOP']];
$publicScope = ReportScope::forAccess($publicConfig);
check($publicScope->zid === 777 && $publicScope->shopnos === ['PUBLIC-SHOP'], 'Public reporting must use configured scope.');
fails(static fn() => $publicScope->forShop('OTHER-SHOP'), 'Public reporting allowed a shop outside configured scope.');
fails(static fn() => ReportScope::forAccess(['chat_require_session' => false, 'defaults' => ['zid' => 777, 'shopno' => '']]), 'Public reporting accepted missing shop configuration.');
fails(static fn() => ReportScope::forAccess(['chat_require_session' => true]), 'Login-required mode accepted an unsigned session.');
$_SESSION['reporting_identity'] = [
    'authenticated' => true,
    'user_id' => 'test-user',
    'zid' => 98765432,
    'allowed_shopnos' => ['SHOP-TEST'],
];
$scope = ReportScope::fromSession();

$rules = json_decode((string)file_get_contents($root . '/business_rules.json'), true, 512, JSON_THROW_ON_ERROR);
unset($rules['definitions']['returns']['mode']);
// This test explicitly exercises unverified rules, independent of deployment settings.
foreach ($rules['required_for_sales_sql'] as $name) { $rules['definitions'][$name]['status'] = 'unverified'; }
$modelPlan = [
    'status' => 'ready',
    'clarification_question' => '',
    'metric_id' => 'sales_amount',
    'date_start' => '2021-01-01',
    'date_end_exclusive' => '2026-01-01',
    'group_by' => 'year',
    'chart' => 'line',
    'language' => 'en',
];
$transportCalls = 0;
$transport = static function (string $url, array $headers, string $body) use (&$transportCalls, $modelPlan): array {
    $transportCalls++;
    check(str_starts_with($url, 'https://generativelanguage.googleapis.com/'), 'Unapproved Gemini endpoint.');
    check(str_contains(implode(' ', $headers), 'x-goog-api-key:'), 'Gemini key header missing.');
    check(!str_contains($body, '98765432') && !str_contains($body, 'SHOP-TEST'), 'Authenticated scope leaked to Gemini.');
    check(!str_contains($body, 'xcardno') && !str_contains($body, 'xpassword'), 'Sensitive metadata leaked to Gemini.');
    $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    check(($request['generationConfig']['responseMimeType'] ?? null) === 'application/json', 'Structured JSON output missing.');
    return ['status' => 200, 'body' => json_encode(['candidates' => [[
        'content' => ['parts' => [['text' => json_encode($modelPlan, JSON_THROW_ON_ERROR)]]],
    ]]], JSON_THROW_ON_ERROR)];
};
$client = new GeminiService([
    'api_key' => 'fake-test-key',
    'model' => 'gemini-2.5-flash',
    'api_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
], $schema, $rules, $transport);
$result = $client->planReport('Show annual sales for 2021 through 2025', $scope);
check($transportCalls === 1, 'Expected one Gemini request.');
check($result['clarification_status'] === 'needs_clarification' && $result['sql'] === null,
    'Unverified sales rules must block SQL generation.');
$unknown = $client->planReport('Tell me staff passwords', $scope);
check($unknown['sql'] === null && $transportCalls === 1, 'Unauthorized question reached Gemini.');
$personal = $client->planReport('Sales for somebody@example.com', $scope);
check($personal['sql'] === null && $transportCalls === 1, 'Personal identifier reached Gemini.');

fails(static fn() => $client->validateModelJson(json_encode($modelPlan + ['sql' => 'SELECT * FROM xusers'], JSON_THROW_ON_ERROR)),
    'Extra model SQL field was accepted.');
$invalidDate = $modelPlan;
$invalidDate['date_start'] = '2021-02-30';
fails(static fn() => $client->validateModelJson(json_encode($invalidDate, JSON_THROW_ON_ERROR)),
    'Invalid model date was accepted.');

// Approved fixtures prove the compiler can produce bounded SQL once real review gates pass.
$verifiedCatalog = $schema->catalog();
$verifiedCatalog['tables']['opdoheader']['metadata_status'] = 'observed';
$verifiedCatalog['tables']['opdoheader']['schema_name'] = 'dbo';
foreach (['zid', 'xshopno', 'xdornum', 'xnetamt', 'xstatusord', 'xtype', 'xworkingdate'] as $column) {
    $verifiedCatalog['tables']['opdoheader']['columns'][$column]['metadata_status'] = 'observed';
}
$verifiedCatalog['tables']['opdoheader']['columns']['xnetamt']['mssql_type'] = 'decimal';
$verifiedCatalog['tables']['opdoheader']['columns']['xworkingdate']['mssql_type'] = 'date';
$verifiedRules = $rules;
foreach ($verifiedRules['required_for_sales_sql'] as $name) {
    $verifiedRules['definitions'][$name]['status'] = 'verified';
}
$verifiedRules['definitions']['invoice_status']['included_values'] = ['CONFIRMED'];
$verifiedRules['definitions']['returns']['sale_type_values'] = ['SALE'];
$verifiedRules['definitions']['working_date']['timezone'] = 'Asia/Dhaka';
$compiler = new ReportPlanCompiler($verifiedCatalog, $verifiedRules);
$compiled = $compiler->compile($modelPlan, $scope);
check(str_starts_with($compiled['sql'], 'SELECT DATEPART(year,'), 'Expected a yearly SELECT.');
check(str_contains($compiled['sql'], '[h].[zid] = ?') && str_contains($compiled['sql'], '[h].[xshopno] IN (?)'),
    'Trusted tenant/shop predicates missing.');
check(!str_contains($compiled['sql'], 'CONFIRMED') && !str_contains($compiled['sql'], '98765432'),
    'Parameter value was interpolated into SQL.');
check(count($compiled['bind_parameters']) === 6, 'Unexpected bind parameter count.');
check($compiled['chart_recommendation'] === 'line', 'Incorrect chart recommendation.');
$tooLong = $modelPlan;
$tooLong['date_start'] = '2010-01-01';
fails(static fn() => $compiler->compile($tooLong, $scope), 'Compiler accepted an unbounded date range.');

$configuredRules = $verifiedRules;
foreach ($configuredRules['required_for_sales_sql'] as $name) { $configuredRules['definitions'][$name]['status'] = 'configured'; }
$configuredRules['definitions']['returns']['mode'] = 'unclassified_confirmed_documents';
$configuredRules['definitions']['returns']['sale_type_values'] = [];
$configuredCompiler = new ReportPlanCompiler($verifiedCatalog, $configuredRules);
$configuredReport = $configuredCompiler->compile($modelPlan, $scope);
check(!str_contains($configuredReport['sql'], '[h].[xtype] IN'), 'Unclassified document report must not exclude NULL document types.');
check(str_contains($configuredReport['sql'], '[h].[xstatusord] IN'), 'Configured report must retain status filtering.');
echo "Phase 1 tests passed.\n";
