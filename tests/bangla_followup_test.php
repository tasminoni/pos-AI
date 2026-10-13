<?php
declare(strict_types=1);
require_once __DIR__.'/../services/GeneratedReportService.php';
function checkFollowup(bool $condition,string $message):void{if(!$condition){throw new RuntimeException($message);}}
session_id('bangla-followup-test'); $_SESSION=[];
$scope=ReportScope::forAccess(['chat_require_session'=>false,'defaults'=>['zid'=>777,'shopno'=>'A']]);
$schema=['tables'=>['dbo.opdoheader'=>['columns'=>['zid'=>'int','xshopno'=>'varchar','xdornum'=>'varchar',
    'xworkingdate'=>'datetime','xnetamt'=>'numeric','xstatusord'=>'varchar']]]];
$summarySql="SELECT SUM(h.xnetamt) AS total FROM dbo.opdoheader AS h WHERE h.xstatusord='Confirmed' AND h.xworkingdate >= '2025-10-13' AND h.xworkingdate < '2026-10-14'";
$listSql="SELECT h.xdornum AS invoice_no, h.xworkingdate AS working_date, h.xnetamt AS net_amount FROM dbo.opdoheader AS h WHERE h.xstatusord='Confirmed' AND h.xworkingdate >= '2025-10-13' AND h.xworkingdate < '2026-10-14' ORDER BY h.xworkingdate DESC, h.xdornum DESC";
$modelCalls=0;
$ai=new GeminiService(['api_key'=>'TEST-SECRET','model'=>'gemini-3.1-flash-lite','api_url'=>'https://generativelanguage.googleapis.com/v1beta/models'],
    new SchemaService(__DIR__.'/../schema_catalog.json'),[],static function($url,$headers,$body)use(&$modelCalls,$summarySql,$listSql):array{
        $modelCalls++;$request=json_decode($body,true);$prompt=json_decode($request['contents'][0]['parts'][0]['text'],true);
        if($modelCalls===2){
            checkFollowup($prompt['question']==='আমি লিস্ট দেখতে চাই','Bangla follow-up was changed.');
            checkFollowup($prompt['prior_report_context']['question']==='আমি এক বছরের সেল দেখতে চাই','Previous Bangla question is missing.');
            checkFollowup($prompt['prior_query']===$summarySql && $prompt['prior_report_context']['date_start']==='2025-10-13','Prior dataset and period were not passed to AI.');
            checkFollowup(isset($prompt['prior_report_context']['columns']),'Prior result-column metadata is missing.');
        }
        checkFollowup(!str_contains($body,'TEST-SECRET') && !str_contains($body,'1549410.00'),'Secrets or database values reached the AI.');
        $plan=['status'=>'ready','sql'=>$modelCalls===1?$summarySql:$listSql,'title'=>$modelCalls===1?'এক বছরের বিক্রয়':'এক বছরের বিক্রয়ের তালিকা','clarification_question'=>''];
        return ['status'=>200,'body'=>json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>json_encode($plan)]]]]]])];
    });
$queryCalls=0;
$client=new QueryApiClient(['url'=>'http://127.0.0.1/api/query.php','token'=>str_repeat('s',40)],
    static function($url,$headers,$body)use(&$queryCalls,$summarySql,$listSql):array{
        $queryCalls++;$envelope=json_decode($body,true);
        checkFollowup($envelope['sql']===($queryCalls===1?$summarySql:$listSql),'Incorrect generated SQL reached the API.');
        checkFollowup($envelope['offset']===($queryCalls===3?2:0),'Pagination offset is incorrect.');
        $columns=$queryCalls===1?[['name'=>'total','type'=>'decimal']]:[['name'=>'invoice_no','type'=>'string'],['name'=>'working_date','type'=>'date'],['name'=>'net_amount','type'=>'decimal']];
        $rows=$queryCalls===1?[['total'=>'1549410.00']]:[
            ['invoice_no'=>$queryCalls===2?'INV-1':'INV-3','working_date'=>'2026-09-01 10:00:00','net_amount'=>'100.00'],
            ['invoice_no'=>$queryCalls===2?'INV-2':'INV-4','working_date'=>'2026-09-01 09:00:00','net_amount'=>'200.00']];
        return ['status'=>200,'body'=>json_encode(['request_id'=>'test-query','status'=>'ok','columns'=>$columns,'rows'=>$rows,
            'row_count'=>count($rows),'scope'=>['zid'=>777,'shopnos'=>['A']],'page_size'=>2,'truncated'=>$queryCalls===2,
            'next_offset'=>$queryCalls===2?2:null,'error'=>null])];
    });
$service=new GeneratedReportService($ai,$client,$schema,['max_rows'=>2]);
$now=new DateTimeImmutable('2026-10-13',new DateTimeZone('Asia/Dhaka'));
$summary=$service->handle('আমি এক বছরের সেল দেখতে চাই',null,$scope,false,$now);
$list=$service->handle('আমি লিস্ট দেখতে চাই',$summary['context'],$scope,false,$now);
checkFollowup($list['response']['status']==='ok' && count($list['response']['table']['columns'])===3,'List follow-up did not return invoice rows.');
checkFollowup($list['response']['time_range']===$summary['response']['time_range'],'Follow-up changed the reporting period.');
checkFollowup($list['response']['pagination']['next_offset']===2,'List next-page link is missing.');
$page=$service->handle('',$list['context'],$scope,true,$now);
checkFollowup($modelCalls===2 && $queryCalls===3 && $page['response']['metadata']['model_calls']===0,'Next page must run stored SQL without another AI call.');
checkFollowup($page['response']['pagination']['offset']===2 && $page['response']['table']['rows'][0]['invoice_no']==='INV-3','Next-page records are incorrect.');
$compiled=(new GeneratedSqlValidator($schema,2))->compile($listSql,$scope,[],2);
checkFollowup(str_contains($compiled['sql'],'TOP (5)') && $compiled['offset']===2 && $compiled['can_page'],'Ordered SQL pagination must bound rows and preserve scope.');
echo "Bangla follow-up and pagination tests passed.\n";
