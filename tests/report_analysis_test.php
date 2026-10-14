<?php
declare(strict_types=1);
require_once __DIR__.'/../services/GeneratedReportService.php';
function checkAnalysis(bool $value,string $message):void{if(!$value){throw new RuntimeException($message);}}
$now=new DateTimeImmutable('2026-04-15',new DateTimeZone('Asia/Dhaka'));
$p=['chart_type'=>'line','x'=>'period','y'=>'sales','period_grain'=>'month','analysis'=>['highest_period','lowest_period','trend','period_change'],'language'=>'en',
    'column_labels'=>[['name'=>'period','label'=>'Month'],['name'=>'sales','label'=>'Sales amount']]];
$result=['columns'=>[['name'=>'period','type'=>'date'],['name'=>'sales','type'=>'decimal']],
    'rows'=>[['period'=>'2026-03-01','sales'=>'150.00'],['period'=>'2026-01-01','sales'=>'100.00'],['period'=>'2026-04-01','sales'=>'999.00'],['period'=>'2026-02-01','sales'=>'200.00']]];
$out=ReportAnalysis::build($result,$p,$now);
checkAnalysis($out['chart']['type']==='line' && $out['chart']['data'][0]['period']==='2026-01-01','Trend charts must be chronological.');
checkAnalysis($out['analysis']['highest_period']['period']==='2026-02-01' && $out['analysis']['highest_period']['value']==='200.00','Current partial month must not be selected as the complete-period peak.');
checkAnalysis($out['analysis']['trend']['direction']==='increasing' && $out['analysis']['trend']['percent_change']===50.0,'First-to-last complete-period growth incorrect.');
checkAnalysis(array_column($out['analysis']['period_changes'],'percent_change')===[100.0,-25.0],'Monthly changes incorrect.');
checkAnalysis(str_contains(implode(' ',$out['insights']),'fluctuate'),'Mixed period directions must not be reported as steady growth.');
checkAnalysis($out['analysis']['latest_change']['direction']==='decreasing' && $out['analysis']['latest_change']['percent_change']===-25.0,'Overall growth must not hide the latest decline.');
checkAnalysis(ReportAnalysis::friendlyText('Highest complete period: 2026-08-01 00:00:00 — 417840.00',$p)==='Highest month: August 2026 — 417,840.00','Insight dates and amounts must be readable.');
$pie=$p;$pie['chart_type']='pie';$pie['language']='bn';
checkAnalysis(ReportAnalysis::build($result,$pie,$now)['chart']['type']==='pie','Explicit pie request ignored.');
$table=$p;$table['chart_type']='table';checkAnalysis(ReportAnalysis::build($result,$table,$now)['chart']['type']==='none','Table-only request should omit chart.');
$negative=$result;$negative['rows'][0]['sales']='-1.00';
checkAnalysis(ReportAnalysis::build($negative,$pie,$now)['chart']['type']==='bar','Pie must not represent negative slices.');
$zero=$result;$zero['rows'][1]['sales']='0.00';
checkAnalysis(ReportAnalysis::build($zero,$p,$now)['analysis']['trend']['percent_change']===null,'Zero baseline percentage must be undefined.');
$partial=ReportAnalysis::build($result,$p,$now,[],true);
checkAnalysis(!isset($partial['analysis']['highest_period'],$partial['analysis']['trend']) && $partial['chart']['partial_page'],'Truncated rows must not generate whole-range analysis.');
$invalid=$p;$invalid['y']='password';
checkAnalysis(ReportAnalysis::build($result,$invalid,$now)['chart']['type']==='none','Axes must match returned numeric columns.');
$null=$result;$null['rows'][0]['sales']=null;
checkAnalysis(ReportAnalysis::build($null,$p,$now)['chart']['type']==='none','Null values must not become zero.');
$cut=ReportAnalysis::build($result,$p,$now,['start'=>'2026-01-15','end_exclusive'=>'2026-04-01']);
checkAnalysis($cut['analysis']['complete_period_count']===2,'Filter-cut month must be excluded.');
$gap=$result;unset($gap['rows'][3]);$gap['rows']=array_values($gap['rows']);
checkAnalysis(ReportAnalysis::build($gap,$p,$now)['analysis']['period_changes'][0]['adjacent_periods']===false,'Calendar gaps must be identified.');
$duplicate=$result;$duplicate['rows'][]=$duplicate['rows'][0];
checkAnalysis(!isset(ReportAnalysis::build($duplicate,$p,$now)['analysis']['trend']),'Repeated calendar buckets must not be analyzed as one series.');
try{ReportAnalysis::validatePresentation(['chart_type'=>'<script>']);throw new RuntimeException('Invalid chart accepted.');}catch(InvalidArgumentException){}

// Exercise AI presentation -> validator -> signed API transport -> backend analysis.
$schema=['tables'=>['dbo.Orders'=>['columns'=>['OrderDate'=>'datetime','Amount'=>'decimal']]]];
$scope=ReportScope::forSchema($schema,['chat_require_session'=>false]);
$sql='SELECT DATEFROMPARTS(YEAR(o.OrderDate),MONTH(o.OrderDate),1) AS period, SUM(o.Amount) AS sales FROM dbo.Orders AS o GROUP BY YEAR(o.OrderDate), MONTH(o.OrderDate) ORDER BY period';
$calls=0;$queryCalls=0;
$ai=new GeminiService(['api_key'=>'TEST-KEY','model'=>'gemini-3.1-flash-lite','api_url'=>'https://generativelanguage.googleapis.com/v1beta/models'],static function($url,$headers,$body)use(&$calls,$sql,$p):array{
    $calls++;$request=json_decode($body,true);$prompt=json_decode($request['contents'][0]['parts'][0]['text'],true);
    checkAnalysis(!str_contains($body,'999.00'),'Database results must never reach AI.');
    $chosen=$p;
    if($calls===2){$chosen['chart_type']='pie';checkAnalysis($prompt['prior_report_context']['presentation']['chart_type']==='line','Follow-up must receive prior axes/analysis choices.');}
    checkAnalysis(isset($request['generationConfig']['responseSchema']['properties']['presentation']),'Provider schema must request structured presentation.');
    return ['status'=>200,'body'=>json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>json_encode(['status'=>'ready','sql'=>$sql,'title'=>'Monthly amounts','clarification_question'=>'','presentation'=>$chosen])]]]]]])];
});
session_id('analysis-test');$_SESSION=[];
$api=new QueryApiClient(['url'=>'http://127.0.0.1/api/query.php','token'=>str_repeat('s',40)],static function($url,$headers,$body)use($result,&$queryCalls):array{
    $queryCalls++;
    return ['status'=>200,'body'=>json_encode($result+['request_id'=>'analysis-query','status'=>'ok','row_count'=>count($result['rows']),
        'scope'=>['zid'=>0,'shopnos'=>[]],'page_size'=>100,'truncated'=>false,'next_offset'=>null,'error'=>null])];
});
$service=new GeneratedReportService($ai,$api,$schema);
$first=$service->handle('Which month is highest and is the trend growing?',null,$scope,false,$now);
checkAnalysis($first['response']['status']==='ok' && $first['response']['analysis']['highest_period']['value']==='200.00','Generated SQL trend reporting failed.');
checkAnalysis($first['response']['table']['columns'][1]['label']==='Sales amount','Friendly labels must match returned aliases without changing row keys.');
$followup=$service->handle('Show that in a pie chart',$first['context'],$scope,false,$now);
checkAnalysis($followup['response']['chart']['type']==='pie' && $calls===2 && $queryCalls===2,'Presentation follow-up must preserve SQL and use one generation call per question.');
echo "Report analysis tests passed.\n";
