<?php
declare(strict_types=1);
require_once __DIR__.'/../services/GeminiService.php';
require_once __DIR__.'/../services/ReportScope.php';
function checkDynamic(bool $value,string $message):void{if(!$value){throw new RuntimeException($message);}}
$path=__DIR__.'/../samples/dynamic_schema.example.json';
$schema=SqlSchema::load($path);
$scope=ReportScope::forAccess(['sql_schema_path'=>$path,'chat_require_session'=>false]);
checkDynamic($scope->zid===0 && $scope->shopnos===[] && $scope->schemaId!=='','Names-only schema must not require POS defaults.');
$sql="SELECT b.bookid AS book_id, b.title AS title, b.price AS price FROM dbo.books AS b WHERE b.price < 100 ORDER BY b.bookid";
$compiled=(new GeneratedSqlValidator($schema))->compile($sql,$scope);
checkDynamic($compiled['params']===[100],'Only the requested amount may be bound, with no POS scope values.');
checkDynamic(str_contains($compiled['sql'],'[dbo].[Books]') && str_contains($compiled['sql'],'[b].[BookId]'),'Resolve canonical identifier spelling.');
$dated=(new GeneratedSqlValidator($schema))->compile($sql,$scope,['date_start'=>'2026-04-01','date_end_inclusive'=>'2026-04-30']);
checkDynamic(str_contains($dated['sql'],'[PublishedDate] >= ?') && array_slice($dated['params'],0,2)===['2026-04-01','2026-05-01'],'Names-only date field must support API filters.');
$ai=new GeminiService(['api_key'=>'TEST-KEY','model'=>'gemini-3.1-flash-lite','api_url'=>'https://generativelanguage.googleapis.com/v1beta/models'],static function($url,$headers,$body)use($sql):array{
    $request=json_decode($body,true);$prompt=json_decode($request['contents'][0]['parts'][0]['text'],true);
    checkDynamic(str_contains($prompt['full_schema']['dbo.Books']['columns']??'', 'Title:unknown'),'AI must receive supplied schema.');
    checkDynamic(!str_contains($body,'opdoheader') && !str_contains($body,'xnetamt') && !str_contains($body,'schema_catalog'),'No POS schema dependency belongs in prompt.');
    return ['status'=>200,'body'=>json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>json_encode(['status'=>'ready','sql'=>$sql,'title'=>'Books','clarification_question'=>''])]]]]]])];
});
checkDynamic($ai->generateSql('List books priced below 100',$schema,null,'2026-10-14')['sql']===$sql,'Unrelated schema generation must work.');
$multiple=SqlSchema::normalize(['tables'=>['Events'=>['EventId','StartDate','EndDate']]]);
try{(new GeneratedSqlValidator($multiple))->compile('SELECT e.EventId AS id FROM dbo.Events AS e',$scope,['date_start'=>'2026-01-01','date_end_inclusive'=>'2026-01-31']);throw new RuntimeException('Ambiguous date must be rejected.');}catch(QueryValidationException){}
echo "Dynamic schema tests passed.\n";
