<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlValidator.php';
require_once dirname(__DIR__) . '/db.php';

final class QueryRunException extends RuntimeException
{
    public function __construct(public readonly string $publicCode = 'QUERY_FAILED', public readonly ?string $safeReason = null)
    {
        parent::__construct('Reporting query failed.');
    }
}

interface QueryResultCursor
{
    /** @return string[] */
    public function columns(): array;
    public function fetch(): ?array;
    public function close(): void;
}

interface QueryDriver
{
    public function run(string $sql, array $params, int $timeoutSeconds): QueryResultCursor;
}

final class SqlsrvResultCursor implements QueryResultCursor
{
    private bool $closed = false;

    public function __construct(private $statement, private array $names) {}

    public function columns(): array { return $this->names; }

    public function fetch(): ?array
    {
        $row = sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_ASSOC);
        if ($row === false) {
            throw new QueryRunException();
        }
        return $row;
    }

    public function close(): void
    {
        if (!$this->closed) {
            sqlsrv_free_stmt($this->statement);
            $this->closed = true;
        }
    }
}

final class SqlsrvQueryDriver implements QueryDriver
{
    public function __construct(private array $reportingConfig, private array $posConfig) {}

    public function run(string $sql, array $params, int $timeoutSeconds): QueryResultCursor
    {
        if (!empty($this->reportingConfig['use_pos_connection'])) {
            DatabaseManager::init($this->posConfig);
            $conn = DatabaseManager::getConnection();
        } else {
            $conn = DatabaseManager::getReportingConnection($this->reportingConfig, $this->posConfig);
        }
        $statement = sqlsrv_query($conn, $sql, $params, ['QueryTimeout' => $timeoutSeconds]);
        if ($statement === false) {
            $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
            $timeout = false;
            $safeReason=null;
            foreach (is_array($errors) ? $errors : [] as $error) {
                if (in_array($error['SQLSTATE'] ?? '', ['HYT00', 'HYT01'], true)) {
                    $timeout = true;
                }
                $safeReason??=match((int)($error['code']??0)){
                    8117=>'aggregate_requires_numeric_expression',8120=>'selected_column_missing_from_group_by',
                    207=>'unknown_column',208=>'unknown_table',102,156=>'sql_syntax_error',
                    245,8114=>'incompatible_expression_types',default=>null,
                };
            }
            throw new QueryRunException($timeout ? 'QUERY_TIMEOUT' : 'QUERY_FAILED',$safeReason);
        }
        $metadata = sqlsrv_field_metadata($statement);
        if (!is_array($metadata)) {
            sqlsrv_free_stmt($statement);
            throw new QueryRunException();
        }
        $names = [];
        foreach ($metadata as $field) {
            $names[] = (string)($field['Name'] ?? '');
        }
        return new SqlsrvResultCursor($statement, $names);
    }
}

/** Runs only SqlValidator output; keeps results and diagnostics bounded. */
final class QueryExecutor
{
    private int $timeoutSeconds;
    private int $maxResponseBytes;
    private $audit;

    public function __construct(private SqlValidator $validator, private QueryDriver $driver, array $limits = [], ?callable $audit = null)
    {
        $this->timeoutSeconds = max(1, min(60, (int)($limits['timeout_seconds'] ?? 20)));
        $this->maxResponseBytes = max(16384, min(2097152, (int)($limits['max_response_bytes'] ?? 1048576)));
        $this->audit = $audit ?? static function (array $entry): void {
            error_log('ai_query_audit ' . json_encode($entry, JSON_UNESCAPED_SLASHES));
        };
    }

    public function execute(array $plan, ReportScope $scope): array
    {
        $requestId = bin2hex(random_bytes(8));
        $started = microtime(true);
        $report = is_string($plan['report'] ?? null) ? $plan['report'] : 'unknown';
        $sqlHash = null;
        $rowCount = 0;
        $outcome = 'QUERY_FAILED';
        try {
            $compiled = $this->validator->validateAndCompile($plan, $scope);
            $sqlHash = hash('sha256', $compiled['sql']);
            $cursor = $this->driver->run($compiled['sql'], $compiled['params'], $this->timeoutSeconds);
            try {
                $expected = array_column($compiled['columns'], 'name');
                if ($cursor->columns() !== $expected) {
                    throw new QueryRunException();
                }
                $rows = [];
                $bytes = 0;
                $truncated = false;
                while (true) {
                    $row = $cursor->fetch();
                    if ($row === null) {
                        break;
                    }
                    if (array_keys($row) !== $expected) {
                        throw new QueryRunException();
                    }
                    if (count($rows) >= $compiled['page_size']) {
                        $truncated = true;
                        break;
                    }
                    $normalized = $this->normalizeRow($row, $compiled['columns']);
                    $size = strlen(json_encode($normalized, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
                    if ($size > $this->maxResponseBytes) {
                        throw new QueryRunException('RESULT_TOO_LARGE');
                    }
                    if ($bytes + $size > $this->maxResponseBytes) {
                        $truncated = true;
                        break;
                    }
                    $bytes += $size;
                    $rows[] = $normalized;
                }
            } finally {
                $cursor->close();
            }
            $rowCount = count($rows);
            $outcome = 'OK';
            $nextOffset = $truncated ? $compiled['offset'] + $rowCount : null;
            if ($nextOffset !== null && ($nextOffset <= $compiled['offset'] || $nextOffset > $compiled['max_offset'])) {
                $nextOffset = null;
            }
            return [
                'request_id' => $requestId, 'status' => 'ok', 'columns' => $compiled['columns'],
                'rows' => $rows, 'row_count' => $rowCount, 'scope' => $compiled['scope'],
                'date_scope' => $compiled['date_scope'], 'truncated' => $truncated,
                'next_offset' => $nextOffset, 'error' => null,
            ];
        } catch (QueryValidationException $error) {
            $outcome = 'PLAN_REJECTED';
            return $this->errorResult($requestId, $scope, 'PLAN_REJECTED', $error->reason);
        } catch (QueryRunException $error) {
            $outcome = $error->publicCode;
            return $this->errorResult($requestId, $scope, $error->publicCode);
        } catch (Throwable) {
            $outcome = 'QUERY_FAILED';
            return $this->errorResult($requestId, $scope, 'QUERY_FAILED');
        } finally {
            $entry = [
                'request_id' => $requestId, 'report' => in_array($report, ['sales_amount', 'product_sales_amount', 'invoice_list', 'invoice_lines'], true) ? $report : 'rejected',
                'sql_template_hash' => $sqlHash,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'row_count' => $rowCount, 'outcome' => $outcome,
            ];
            try {
                ($this->audit)($entry);
            } catch (Throwable) {
                error_log('ai_query_audit_fallback ' . json_encode($entry, JSON_UNESCAPED_SLASHES));
            }
        }
    }

    private function normalizeRow(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            $name = $column['name'];
            $value = $row[$name];
            if ($value instanceof DateTimeInterface) {
                $row[$name] = $column['type'] === 'date' ? $value->format('Y-m-d') : $value->format(DATE_ATOM);
            } elseif (is_resource($value) || (is_float($value) && !is_finite($value))) {
                throw new QueryRunException();
            }
        }
        return $row;
    }

    private function errorResult(string $requestId, ReportScope $scope, string $code, ?string $reason = null): array
    {
        return [
            'request_id' => $requestId, 'status' => 'error', 'columns' => [], 'rows' => [],
            'row_count' => 0, 'scope' => ['zid' => $scope->zid, 'shopnos' => $scope->shopnos],
            'date_scope' => null, 'truncated' => false, 'next_offset' => null,
            'error' => ['code' => $code, 'message' => match ($code) {
                'PLAN_REJECTED' => 'The report plan is not approved.',
                'QUERY_TIMEOUT' => 'The report timed out.',
                'RESULT_TOO_LARGE' => 'The report result is too large.',
                default => 'The report could not be completed.',
            }, 'reason' => $reason],
        ];
    }
}
