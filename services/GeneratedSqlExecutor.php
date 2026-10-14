<?php
declare(strict_types=1);
require_once __DIR__ . '/GeneratedSqlValidator.php';
require_once __DIR__ . '/QueryDriver.php';

final class GeneratedSqlExecutor
{
    public function __construct(private GeneratedSqlValidator $validator, private QueryDriver $driver, private array $limits = []) {}

    public function execute(string $sql, ReportScope $scope, array $filters = [], int $offset = 0): array
    {
        $id = bin2hex(random_bytes(8)); $started = microtime(true); $outcome = 'QUERY_FAILED';
        $safeReason=null;
        $result = ['request_id'=>$id, 'status'=>'error', 'columns'=>[], 'rows'=>[], 'row_count'=>0,
            'scope'=>['zid'=>$scope->zid,'shopnos'=>$scope->shopnos], 'date_scope'=>null,
            'truncated'=>false, 'next_offset'=>null, 'error'=>null];
        if($scope->schemaId!==''){$result['scope']['schema_id']=$scope->schemaId;}
        try {
            $compiled = $this->validator->compile($sql, $scope, $filters, $offset);
            $cursor = $this->driver->run($compiled['sql'], $compiled['params'], min(30, max(1, (int)($this->limits['timeout_seconds'] ?? 10))));
            try {
                $names = $cursor->columns();
                if (!$names || count($names) > 40 || count(array_unique($names)) !== count($names) || in_array('', $names, true)) { throw new QueryRunException(); }
                $rows = []; $bytes = 0;
                $skipped = 0;
                while (($row = $cursor->fetch()) !== null) {
                    if ($skipped < $offset) { $skipped++; continue; }
                    if (count($rows) >= $compiled['limit']) { $result['truncated'] = true; break; }
                    foreach ($row as &$value) {
                        if ($value instanceof DateTimeInterface) { $value = $value->format('Y-m-d H:i:s'); }
                        if (is_resource($value) || (is_float($value) && !is_finite($value))) { throw new QueryRunException(); }
                    }
                    unset($value);
                    $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                    if ($bytes > min(2097152, (int)($this->limits['max_response_bytes'] ?? 1048576))) { throw new QueryRunException('RESULT_TOO_LARGE'); }
                    $rows[] = $row;
                }
            } finally { $cursor->close(); }
            $columns = [];
            foreach ($names as $name) {
                $values = array_values(array_filter(array_column($rows, $name), static fn($v)=>$v !== null));
                $type = 'string';
                if (!$values && in_array($name,$compiled['aggregate_aliases'],true)) { $type='decimal'; }
                if ($values && count(array_filter($values, 'is_int')) === count($values)) { $type = 'integer'; }
                elseif ($values && !preg_match('/(?:_id|_no|code|shop|item|sku|product)/i', $name)
                    && count(array_filter($values, static fn($v)=>is_scalar($v) && preg_match('/^-?\d+(?:\.\d+)?$/D', (string)$v))) === count($values)) { $type = 'decimal'; }
                elseif ($values && count(array_filter($values, static fn($v)=>is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v))) === count($values)) { $type = 'date'; }
                $columns[] = ['name'=>$name, 'type'=>$type];
            }
            $outcome = 'OK';
            $result['status']='ok'; $result['columns']=$columns; $result['rows']=$rows; $result['row_count']=count($rows);
            $result['page_size']=$compiled['limit'];
            $next=$offset+$compiled['limit'];
            $result['next_offset']=$result['truncated'] && $compiled['can_page'] && $next<=10000?$next:null;
            return $result;
        } catch (QueryValidationException) { $outcome='PLAN_REJECTED'; }
        catch (QueryRunException $error) { $outcome=$error->publicCode; $safeReason=$error->safeReason; }
        catch (Throwable) { $outcome='QUERY_FAILED'; }
        finally {
            error_log('ai_generated_sql_audit '.json_encode(['request_id'=>$id, 'sql_hash'=>hash('sha256',$sql),
                'duration_ms'=>(int)round((microtime(true)-$started)*1000), 'outcome'=>$outcome]));
        }
        $result['error']=['code'=>$outcome,'message'=>match($outcome) {
            'PLAN_REJECTED'=>'The AI query uses unsupported SQL or restricted database fields. Please rephrase.',
            'QUERY_TIMEOUT'=>'The query timed out. Try a narrower period.',
            default=>'The generated report could not be executed.',
        }];
        if($safeReason!==null){$result['error']['reason']=$safeReason;}
        return $result;
    }
}
