<?php
declare(strict_types=1);

// CLI, or a loopback POST with a custom header (no cross-origin browser writes).
if (PHP_SAPI !== 'cli' && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || ($_SERVER['HTTP_X_SCHEMA_REFRESH'] ?? '') !== '1')) {
    http_response_code(404); exit;
}
require_once __DIR__.'/../db.php';
try {
    $rows=DatabaseManager::query('SELECT s.name AS schema_name, t.name AS table_name, c.name AS column_name, ty.name AS data_type '
        .'FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id JOIN sys.columns c ON c.object_id=t.object_id '
        .'JOIN sys.types ty ON ty.user_type_id=c.user_type_id ORDER BY s.name,t.name,c.column_id');
    if (!$rows) { throw new RuntimeException('No live schema metadata returned.'); }
    $tables=[];
    foreach($rows as $row) { $tables[$row['schema_name'].'.'.$row['table_name']]['columns'][$row['column_name']]=$row['data_type']; }
    $path=__DIR__.'/../sql_schema.json';
    $existing=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];
    foreach($tables as $name=>&$table){
        $annotations=$existing['tables'][$name]??[];
        unset($annotations['columns']);
        $table=array_replace($annotations,$table);
    }
    unset($table);
    $json=json_encode(array_replace($existing,['observed_at'=>gmdate('c'),'tables'=>$tables]),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    if (file_put_contents($path,$json,LOCK_EX)===false) { throw new RuntimeException('Schema snapshot could not be saved.'); }
    header('Content-Type: application/json');
    echo json_encode(['success'=>true,'tables'=>count($tables),'columns'=>count($rows)]);
} catch(Throwable $error) {
    error_log('SQL schema refresh failed: '.get_class($error)); http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Could not refresh the database schema.']);
    if (PHP_SAPI==='cli') { exit(1); }
}
