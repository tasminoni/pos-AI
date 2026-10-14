<?php
declare(strict_types=1);
require_once __DIR__.'/../services/InternalQueryAuth.php';
function checkAuth(bool $value,string $message):void{if(!$value){throw new RuntimeException($message);}}
function rejectsAuth(callable $action):void{
    try{$action();}catch(RuntimeException){return;}
    throw new RuntimeException('An invalid internal request was accepted.');
}
$secret=str_repeat('k',40);
$body=json_encode(['sql'=>'SELECT b.Title FROM dbo.Books AS b','filters'=>[]],JSON_THROW_ON_ERROR);
$headers=InternalQueryAuth::headersFor($body,$secret,1700000000,str_repeat('a',32));
$nonce=InternalQueryAuth::verifySignature($body,$headers,$secret,60,1700000000);
checkAuth($nonce===str_repeat('a',32),'Signed SQL request was rejected.');
rejectsAuth(static fn()=>InternalQueryAuth::verifySignature($body.' ',$headers,$secret,60,1700000000));
rejectsAuth(static fn()=>InternalQueryAuth::verifySignature($body,$headers,$secret,60,1700000061));
rejectsAuth(static fn()=>InternalQueryAuth::verifySignature($body,$headers,str_repeat('z',40),60,1700000000));
$cache=[];
InternalQueryAuth::claimNonce($nonce,$cache,60,1700000000);
rejectsAuth(static function()use($nonce,&$cache){InternalQueryAuth::claimNonce($nonce,$cache,60,1700000000);});
checkAuth(InternalQueryAuth::isAllowedPeer('127.0.0.1','127.0.0.1,::1'),'Loopback must be permitted.');
checkAuth(!InternalQueryAuth::isAllowedPeer('203.0.113.9','127.0.0.1,::1'),'Unlisted peer must be blocked.');
echo "Internal authentication tests passed.\n";
