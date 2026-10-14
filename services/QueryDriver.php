<?php
declare(strict_types=1);

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

