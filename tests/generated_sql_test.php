<?php
declare(strict_types=1);
require_once __DIR__.'/../services/GeneratedSqlExecutor.php';
require_once __DIR__.'/../services/GeminiService.php';
require_once __DIR__.'/../services/GeneratedReportService.php';
function assertSql(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$schema=['tables'=>[
    'dbo.opdoheader'=>['columns'=>['zid'=>'int','xshopno'=>'varchar','xdornum'=>'varchar','xnetamt'=>'numeric','xworkingdate'=>'datetime','xstatusord'=>'varchar','xpassword'=>'varchar']],
    'dbo.opdodetail'=>['columns'=>['zid'=>'int','xshopno'=>'varchar','xdornum'=>'varchar','xitem'=>'varchar','xqty'=>'numeric']],
    'dbo.xusers'=>['columns'=>['zid'=>'int','xpassword'=>'varchar']],
]];
$scope=ReportScope::forAccess(['chat_require_session'=>false,'defaults'=>['zid'=>777,'shopno'=>'A']]);
$validator=new GeneratedSqlValidator($schema,100);
$sql="SELECT SUM(h.xnetamt) AS total FROM dbo.opdoheader AS h WHERE h.xstatusord = 'Confirmed' AND h.xworkingdate >= '2026-09-01' AND h.xworkingdate < '2026-10-01'";
$compiled=$validator->compile($sql,$scope);
assertSql($compiled['params']===[777,'A','Confirmed','2026-09-01','2026-10-01'],'Scope and literal parameter order is incorrect.');
assertSql(str_contains($compiled['sql'],'FROM (SELECT') && str_contains($compiled['sql'],'[zid] = ? AND [xshopno] IN (?)'),'Table source must be scoped independently of model predicates.');
assertSql(!str_contains($compiled['sql'],'xpassword'),'Restricted source columns leaked into execution SQL.');
$document='DO2101FSM-000022026041300001';
$documentSql="SELECT h.xdornum AS document, h.xnetamt AS net_amount, h.xstatusord AS status FROM dbo.opdoheader AS h WHERE h.xdornum='$document'";
$documentQuery=$validator->compile($documentSql,$scope);
assertSql($documentQuery['params']===[777,'A',$document], 'Alphanumeric xdornum must retain every character as a bound string.');
assertSql(!str_contains($documentQuery['sql'],$document),'Document identifier must not be interpolated into executed SQL.');
$joined=$validator->compile('SELECT d.xitem AS item, SUM(d.xqty) AS qty FROM dbo.opdoheader AS h INNER JOIN dbo.opdodetail AS d ON h.zid=d.zid AND h.xshopno=d.xshopno AND h.xdornum=d.xdornum GROUP BY d.xitem ORDER BY qty DESC',$scope);
assertSql($joined['params']===[777,'A',777,'A'],'Every joined source must be independently scoped.');
$selected=$validator->compile("SELECT TOP 5 CAST(h.xworkingdate AS date) AS day, COUNT(*) AS invoices FROM dbo.opdoheader AS h GROUP BY CAST(h.xworkingdate AS date) ORDER BY day",$scope);
assertSql($selected['limit']===5,'Model TOP must be bounded.');
assertSql(str_contains($selected['sql'],'TOP (5)'),'A requested top-five result must not be treated as a truncated six-row page.');
$withFilters=$validator->compile($sql,$scope,['date_start'=>'2026-09-02','date_end_inclusive'=>'2026-09-03']);
assertSql(array_slice($withFilters['params'],0,4)===[777,'A','2026-09-02','2026-09-04'],'User dates must also be enforced by the API.');
foreach ([
    'DELETE FROM dbo.opdoheader',
    'SELECT h.xnetamt INTO dbo.stolen FROM dbo.opdoheader AS h',
    'SELECT h.xnetamt FROM dbo.opdoheader AS h; DROP TABLE dbo.opdoheader',
    'SELECT h.xnetamt FROM dbo.opdoheader AS h UNION SELECT u.xpassword FROM dbo.xusers AS u',
    'SELECT u.xpassword FROM dbo.xusers AS u',
    'SELECT h.xpassword FROM dbo.opdoheader AS h',
    "SELECT OPENROWSET('provider','server','query') AS leak FROM dbo.opdoheader AS h",
    'SELECT * FROM dbo.opdoheader AS h',
    'SELECT (SELECT COUNT(*) FROM dbo.xusers AS u) AS hidden FROM dbo.opdoheader AS h',
    'SELECT h.xnetamt FROM master.sys.tables AS h',
    'SELECT h.xnetamt FROM dbo.opdoheader AS h -- bypass',
    "'SELECT' h.xnetamt FROM dbo.opdoheader AS h",
] as $attack) {
    $rejected=false; try { $validator->compile($attack,$scope); } catch(QueryValidationException) { $rejected=true; }
    assertSql($rejected,'Unsafe SQL accepted: '.$attack);
}
$tautology=$validator->compile('SELECT h.xnetamt FROM dbo.opdoheader AS h WHERE 1=1 OR h.zid=123',$scope);
assertSql(array_slice($tautology['params'],0,2)===[777,'A'],'An OR predicate must not escape the source scope.');
$capture=[];
$ai=new GeminiService(['api_key'=>'SECRET-KEY','model'=>'gemini-2.5-flash','api_url'=>'https://generativelanguage.googleapis.com/v1beta/models'],
    new SchemaService(__DIR__.'/../schema_catalog.json'),[],static function($url,$headers,$body)use(&$capture,$sql):array{
        $capture=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        assertSql(!str_contains($body,'SECRET-KEY') && !str_contains($body,'777'),'Model prompt leaked credentials or tenant.');
        return ['status'=>200,'body'=>json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>json_encode(['status'=>'ready','sql'=>$sql,'title'=>'Confirmed net amounts','clarification_question'=>''])]]]]]])];
    });
$plan=$ai->generateSql('How much did we sell in September?',$schema,null,'2026-10-13');
assertSql($plan['sql']===$sql,'AI-generated SQL was not returned.');
assertSql($capture['generationConfig']['thinkingConfig']['thinkingBudget']===0,'Fast SQL generation should disable thinking for Gemini 2.5 Flash.');
final class GeneratedTestCursor implements QueryResultCursor {
    private int $position=0;
    public function columns():array{return ['total'];}
    public function fetch():?array{return $this->position++===0?['total'=>'284425.00']:null;}
    public function close():void{}
}
final class GeneratedTestDriver implements QueryDriver {
    public int $calls=0;
    public array $params=[];
    public function run(string $sql,array $params,int $timeoutSeconds):QueryResultCursor {
        $this->calls++;$this->params=$params;return new GeneratedTestCursor();
    }
}
$driver=new GeneratedTestDriver();
$executor=new GeneratedSqlExecutor(new GeneratedSqlValidator($schema,100),$driver);
$success=$executor->execute($sql,$scope);
assertSql($success['status']==='ok' && $success['rows'][0]['total']==='284425.00'
    && $success['columns'][0]['type']==='decimal','Generated SQL results must retain decimal precision.');
$failure=$executor->execute('DROP TABLE dbo.opdoheader',$scope);
assertSql($failure['error']['code']==='PLAN_REJECTED' && $driver->calls===1,'Invalid SQL reached the database driver.');
session_id('generated-sql-test');
$_SESSION=[];
$queryCalls=0;
$client=new QueryApiClient(['url'=>'http://127.0.0.1/api/query.php','token'=>str_repeat('t',40)],
    static function($url,$headers,$body)use(&$queryCalls,$success,$sql):array{
        $queryCalls++;
        $envelope=json_decode($body,true);
        assertSql($envelope['sql']===$sql && isset($envelope['filters']),'AI SQL was not sent to the Query API.');
        return ['status'=>200,'body'=>json_encode($success)];
    });
$reporter=new GeneratedReportService($ai,$client,$schema);
$first=$reporter->handle('September sales',null,$scope,false,new DateTimeImmutable('2026-10-13'));
assertSql($first['response']['status']==='ok' && $first['response']['metadata']['model_calls']===1,'AI SQL reporting failed.');
$_SESSION['_sql_generation_cache'][$first['context']['cache_key']]=['plan'=>$first['context']['cache_plan'],'expires'=>time()+600];
$again=$reporter->handle('September sales',null,$scope,false,new DateTimeImmutable('2026-10-13'));
assertSql($again['response']['metadata']['model_calls']===0 && $queryCalls===2,'Cached SQL must still fetch fresh database results.');
echo "Generated SQL tests passed.\n";
