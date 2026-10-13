<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/InternalQueryAuth.php';
require_once __DIR__ . '/../services/QueryExecutor.php';

function ok(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function rejected(callable $action, string $message): void
{
    try { $action(); } catch (QueryValidationException) { return; }
    throw new RuntimeException($message);
}

final class FakeCursor implements QueryResultCursor
{
    public int $fetches = 0;
    public bool $closed = false;
    private int $index = 0;
    public function __construct(private array $names, private array $rows) {}
    public function columns(): array { return $this->names; }
    public function fetch(): ?array
    {
        $this->fetches++;
        return $this->rows[$this->index++] ?? null;
    }
    public function close(): void { $this->closed = true; }
}

final class FakeDriver implements QueryDriver
{
    public int $calls = 0;
    public ?FakeCursor $lastCursor = null;
    public array $lastParams = [];
    public int $lastTimeout = 0;
    public bool $timeout = false;
    public array $rows = [];
    public array $names = [];
    public function run(string $sql, array $params, int $timeoutSeconds): QueryResultCursor
    {
        $this->calls++;
        $this->lastParams = $params;
        $this->lastTimeout = $timeoutSeconds;
        if ($this->timeout) { throw new QueryRunException('QUERY_TIMEOUT'); }
        return $this->lastCursor = new FakeCursor($this->names, $this->rows);
    }
}

$root = dirname(__DIR__);
$catalog = json_decode((string)file_get_contents($root . '/schema_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$rules = json_decode((string)file_get_contents($root . '/business_rules.json'), true, 512, JSON_THROW_ON_ERROR);
unset($rules['definitions']['returns']['mode']);
foreach (['opdoheader', 'opdodetail'] as $name) {
    $catalog['tables'][$name]['metadata_status'] = 'observed';
    $catalog['tables'][$name]['schema_name'] = 'dbo';
    foreach ($catalog['tables'][$name]['columns'] as &$column) {
        $column['metadata_status'] = 'observed';
    }
    unset($column);
}
$catalog['tables']['opdoheader']['columns']['xnetamt']['mssql_type'] = 'decimal';
$catalog['tables']['opdoheader']['columns']['xworkingdate']['mssql_type'] = 'date';
$catalog['tables']['opdodetail']['columns']['xnetamt']['mssql_type'] = 'decimal';
$catalog['tables']['opdodetail']['columns']['xqty']['mssql_type'] = 'decimal';
foreach ($rules['required_for_sales_sql'] as $name) {
    $rules['definitions'][$name]['status'] = 'verified';
}
$rules['definitions']['invoice_status']['included_values'] = ['TEST_CONFIRMED'];
$rules['definitions']['returns']['sale_type_values'] = ['TEST_SALE'];
$rules['definitions']['working_date']['timezone'] = 'Asia/Dhaka';
$rules['joins']['header_detail']['status'] = 'verified';

session_start();
$_SESSION['reporting_identity'] = [
    'authenticated' => true, 'user_id' => 'test-user', 'zid' => 777777,
    'allowed_shopnos' => ['A', 'B'],
];
$scope = ReportScope::fromSession();
$limits = ['max_rows' => 3, 'max_offset' => 10, 'max_detail_days' => 30,
    'timeout_seconds' => 4, 'max_response_bytes' => 16384];
$validator = new SqlValidator($catalog, $rules, $limits);
$list = [
    'report' => 'invoice_list', 'group_by' => 'none',
    'date_start' => '2025-01-01', 'date_end_exclusive' => '2025-01-10',
    'page_size' => 2, 'offset' => 0,
];
$normal = $validator->validateAndCompile($list, $scope);
ok(str_starts_with($normal['sql'], 'SELECT ') && !str_contains($normal['sql'], ';'), 'Normal report is not a single SELECT.');
ok(str_contains($normal['sql'], 'FROM [dbo].[opdoheader] AS [h]'), 'Unapproved SQL table was used.');
ok(str_contains($normal['sql'], '[h].[zid] = ?') && str_contains($normal['sql'], '[h].[xshopno] IN (?, ?)'),
    'Header scope predicates missing.');
ok(!str_contains($normal['sql'], '777777') && !str_contains($normal['sql'], 'TEST_CONFIRMED'), 'Values were interpolated.');
ok(substr_count($normal['sql'], '?') === count($normal['params']), 'Normal query bind count mismatch.');
ok($normal['params'] === ['2025-01-01', '2025-01-10', 777777, 'A', 'B', 0, 3], 'Normal bind order incorrect.');
$oneShopPlan = $list;
$oneShopPlan['shop_filter'] = 'B';
$oneShopSql = $validator->validateAndCompile($oneShopPlan, $scope);
ok($oneShopSql['scope']['shopnos'] === ['B']
    && $oneShopSql['params'] === ['2025-01-01', '2025-01-10', 777777, 'B', 0, 3],
    'Authorized shop narrowing failed.');
$otherShopPlan = $list;
$otherShopPlan['shop_filter'] = 'OTHER';
rejected(static fn() => $validator->validateAndCompile($otherShopPlan, $scope),
    'Unauthorized shop selector was accepted.');

$aggregate = [
    'report' => 'sales_amount', 'group_by' => 'year',
    'date_start' => null, 'date_end_exclusive' => null,
    'page_size' => 2, 'offset' => 0,
];
$grouped = $validator->validateAndCompile($aggregate, $scope);
ok(str_contains($grouped['sql'], 'SUM([h].[xnetamt])') && str_contains($grouped['sql'], 'GROUP BY DATEPART(year,'),
    'All-history aggregate was not compiled.');
ok(!str_contains($grouped['sql'], '[h].[xworkingdate] >= ?'), 'All-history aggregate was date-limited.');
ok(substr_count($grouped['sql'], '?') === count($grouped['params']), 'Aggregate bind count mismatch.');
ok($grouped['date_scope']['all_authorized_history'] === true, 'All-history scope missing.');

$lines = $list;
$lines['report'] = 'invoice_lines';
$joined = $validator->validateAndCompile($lines, $scope);
ok(str_contains($joined['sql'], 'INNER JOIN [dbo].[opdodetail] AS [d]'), 'Approved join missing.');
ok(str_contains($joined['sql'], '[d].[zid] = [h].[zid]') && str_contains($joined['sql'], '[d].[xshopno] = [h].[xshopno]'),
    'Composite join scope missing.');
ok(str_contains($joined['sql'], '[d].[zid] = ?') && str_contains($joined['sql'], '[d].[xshopno] IN (?, ?)'),
    'Joined table is not directly scoped.');
ok(substr_count($joined['sql'], '?') === count($joined['params']), 'Join bind count mismatch.');

foreach ([
    ['sql' => 'SELECT * FROM xusers'], ['zid' => 1], ['shopnos' => ['OTHER']],
    ['join' => ['table' => 'xusers']], ['nested_query' => 'SELECT * FROM xusers'],
    ['table' => 'master.dbo.xusers'], ['where' => '1=1; DROP TABLE xusers'],
] as $payload) {
    rejected(static fn() => $validator->validateAndCompile($list + $payload, $scope), 'Extra SQL/scope/payload field accepted.');
}
foreach (['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'EXEC', 'SELECT 1; SELECT 2', 'invoice_list--', 'sys.tables'] as $unsafe) {
    $attack = $list;
    $attack['report'] = $unsafe;
    rejected(static fn() => $validator->validateAndCompile($attack, $scope), 'Unsafe report selector accepted.');
}
$badDate = $list;
$badDate['date_start'] = "2025-01-01'; DROP TABLE xusers;--";
rejected(static fn() => $validator->validateAndCompile($badDate, $scope), 'Injected date accepted.');
$badDate['date_start'] = "2025-01-01\0";
rejected(static fn() => $validator->validateAndCompile($badDate, $scope), 'Null-byte date was not rejected as a plan error.');
$tooLong = $list;
$tooLong['date_end_exclusive'] = '2025-03-01';
rejected(static fn() => $validator->validateAndCompile($tooLong, $scope), 'Unbounded detail range accepted.');
$unboundedDetail = $list;
$unboundedDetail['date_start'] = $unboundedDetail['date_end_exclusive'] = null;
rejected(static fn() => $validator->validateAndCompile($unboundedDetail, $scope), 'All-history raw rows accepted.');
$unboundedDay = $aggregate;
$unboundedDay['group_by'] = 'day';
rejected(static fn() => $validator->validateAndCompile($unboundedDay, $scope), 'All-history daily grouping accepted.');
$tooMany = $list;
$tooMany['page_size'] = 999999;
rejected(static fn() => $validator->validateAndCompile($tooMany, $scope), 'Massive page accepted.');
$offset = $list;
$offset['offset'] = 999999;
rejected(static fn() => $validator->validateAndCompile($offset, $scope), 'Massive offset accepted.');
$badSchema = $catalog;
$badSchema['tables']['opdoheader']['schema_name'] = 'dbo].[xusers';
rejected(static fn() => (new SqlValidator($badSchema, $rules, $limits))->validateAndCompile($list, $scope),
    'Unsafe schema name accepted.');
$unapproved = $catalog;
$unapproved['tables']['opdoheader']['approved_for_gemini'] = false;
rejected(static fn() => (new SqlValidator($unapproved, $rules, $limits))->validateAndCompile($aggregate, $scope),
    'Unapproved table accepted.');
$badJoin = $rules;
$badJoin['joins']['header_detail']['status'] = 'unverified';
rejected(static fn() => (new SqlValidator($catalog, $badJoin, $limits))->validateAndCompile($lines, $scope),
    'Unverified join accepted.');

$secret = str_repeat('k', 40);
ok(InternalQueryAuth::isAllowedPeer('127.0.0.1', '127.0.0.1,::1'), 'Loopback peer was rejected.');
ok(!InternalQueryAuth::isAllowedPeer('203.0.113.9', '127.0.0.1,::1'), 'External peer was accepted.');
$body = json_encode(['plan' => $list], JSON_THROW_ON_ERROR);
$headers = InternalQueryAuth::headersFor($body, $secret, 1700000000, str_repeat('a', 32));
$nonce = InternalQueryAuth::verifySignature($body, $headers, $secret, 60, 1700000000);
ok($nonce === str_repeat('a', 32), 'Internal HMAC was not verified.');
$cache = [];
InternalQueryAuth::claimNonce($nonce, $cache, 60, 1700000000);
try { InternalQueryAuth::claimNonce($nonce, $cache, 60, 1700000000); throw new RuntimeException('Replay accepted.'); }
catch (RuntimeException $error) { ok($error->getMessage() !== 'Replay accepted.', 'Replay accepted.'); }
foreach ([
    static fn() => InternalQueryAuth::verifySignature($body . ' ', $headers, $secret, 60, 1700000000),
    static fn() => InternalQueryAuth::verifySignature($body, $headers, $secret, 60, 1700000100),
    static fn() => InternalQueryAuth::verifySignature($body, $headers, str_repeat('x', 40), 60, 1700000000),
] as $badSignature) {
    try { $badSignature(); throw new RuntimeException('Invalid internal signature accepted.'); }
    catch (RuntimeException $error) { ok($error->getMessage() !== 'Invalid internal signature accepted.', 'Invalid internal signature accepted.'); }
}

$pos = ['uid' => 'main_user'];
$reporting = ['server' => 'test', 'database' => 'test', 'uid' => 'main_user', 'pwd' => 'fake', 'trust_cert' => 'No'];
try { DatabaseManager::getReportingConnection($reporting, $pos); throw new RuntimeException('Shared SQL account accepted.'); }
catch (RuntimeException $error) { ok($error->getMessage() !== 'Shared SQL account accepted.', 'Shared SQL account accepted.'); }
$roles = ['principal' => 'report_reader', 'db_owner' => 0, 'db_datareader' => 0,
    'db_datawriter' => 0, 'db_ddladmin' => 0, 'db_securityadmin' => 0,
    'db_accessadmin' => 0, 'db_backupoperator' => 0, 'sysadmin' => 0];
ok(DatabaseManager::isLeastPrivilegeProfile($roles, ['CONNECT']), 'Minimal DB profile was rejected.');
ok(!DatabaseManager::isLeastPrivilegeProfile(array_replace($roles, ['db_datawriter' => 1]), ['CONNECT']),
    'Writer role was approved.');
ok(!DatabaseManager::isLeastPrivilegeProfile($roles, ['CONNECT', 'SELECT']),
    'Database-wide SELECT was approved.');

$driver = new FakeDriver();
$driver->names = array_column($normal['columns'], 'name');
$sampleRow = [
    'invoice_no' => 'INV-TEST', 'working_date' => new DateTimeImmutable('2025-01-01'),
    'shop' => 'A', 'net_amount' => '12.50', 'invoice_status' => 'TEST_CONFIRMED', 'document_type' => 'TEST_SALE',
];
$driver->rows = array_fill(0, 1000, $sampleRow);
$audits = [];
$executor = new QueryExecutor($validator, $driver, $limits, static function (array $entry) use (&$audits): void { $audits[] = $entry; });
$result = $executor->execute($list, $scope);
ok($result['status'] === 'ok' && $result['row_count'] === 2 && $result['truncated'] === true && $result['next_offset'] === 2,
    'Large result was not safely paged.');
ok($driver->lastCursor->fetches === 3 && $driver->lastCursor->closed, 'Executor read beyond row cap or left cursor open.');
ok($driver->lastTimeout === 4 && $result['rows'][0]['working_date'] === '2025-01-01', 'Timeout or date normalization failed.');
ok(!str_contains(json_encode($audits, JSON_THROW_ON_ERROR), 'INV-TEST')
    && !str_contains(json_encode($audits, JSON_THROW_ON_ERROR), '777777'), 'Sensitive values entered audit log.');

$driver->timeout = true;
$timedOut = $executor->execute($list, $scope);
ok($timedOut['status'] === 'error' && $timedOut['error']['code'] === 'QUERY_TIMEOUT', 'Timeout was not sanitized.');
$driver->timeout = false;
$driver->rows = [array_replace($sampleRow, ['invoice_no' => str_repeat('X', 20000)])];
$large = $executor->execute($list, $scope);
ok($large['error']['code'] === 'RESULT_TOO_LARGE' && $large['rows'] === [], 'Oversized row was returned.');
$before = $driver->calls;
$crossShop = $executor->execute($list + ['shopnos' => ['OTHER']], $scope);
ok($crossShop['error']['code'] === 'PLAN_REJECTED' && $driver->calls === $before,
    'Cross-shop plan reached the driver.');

echo "Phase 2 tests passed.\n";
