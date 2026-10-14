<?php
declare(strict_types=1);

// Read-only live SQL Server metadata export. Run from the command line.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/db.php';

function metadata($connection, string $sql): array
{
    $statement = sqlsrv_query($connection, $sql, [], ['QueryTimeout' => 60]);
    if ($statement === false) { throw new RuntimeException('Metadata query failed.'); }
    $rows = [];
    try {
        while (($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) !== null) {
            if ($row === false) { throw new RuntimeException('Metadata fetch failed.'); }
            foreach ($row as &$value) {
                if ($value instanceof DateTimeInterface) { $value = $value->format(DATE_ATOM); }
            }
            unset($value);
            $rows[] = $row;
        }
    } finally { sqlsrv_free_stmt($statement); }
    return $rows;
}

try {
    $connectionMode = 'project connection';
    try { $connection = DatabaseManager::getConnection(); }
    catch (Throwable $connectionError) {
        // A local SQL Server may lack a usable TLS credential. Never relax
        // transport encryption for a remote server.
        $config = require dirname(__DIR__) . '/config.php';
        $db = $config['db'];
        if (!in_array(strtolower($db['server']), ['.', 'localhost', '127.0.0.1', '(local)'], true)
            || !function_exists('sqlsrv_connect')) { throw $connectionError; }
        $connection = sqlsrv_connect($db['server'], ['Database' => $db['database'],
            'UID' => $db['uid'], 'PWD' => $db['pwd'], 'CharacterSet' => 'UTF-8',
            'Encrypt' => false, 'TrustServerCertificate' => true, 'LoginTimeout' => 5]);
        if ($connection === false) { throw $connectionError; }
        $connectionMode = 'local-only connection with Encrypt=false';
    }
    if ($connection === null) { throw new RuntimeException('A live database connection is required.'); }
    $queries = [
        'database' => "SELECT name, collation_name, compatibility_level, recovery_model_desc, state_desc, is_read_only, snapshot_isolation_state_desc, is_read_committed_snapshot_on FROM sys.databases WHERE database_id=DB_ID()",
        'visibility' => "SELECT HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'VIEW DEFINITION') AS can_view_database_definition",
        'schemas' => "SELECT schema_id, name, USER_NAME(principal_id) AS owner_name FROM sys.schemas ORDER BY name",
        'objects' => "SELECT o.object_id, s.name AS schema_name, o.name, o.type, o.type_desc, o.create_date, o.modify_date FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE o.is_ms_shipped=0 ORDER BY s.name,o.name",
        'tables' => "SELECT t.object_id, s.name AS schema_name, t.name AS table_name, t.is_memory_optimized, t.durability_desc, t.temporal_type_desc FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.is_ms_shipped=0 ORDER BY s.name,t.name",
        'columns' => "SELECT c.object_id, s.name AS schema_name, o.name AS object_name, c.column_id, c.name AS column_name, ts.name AS type_schema, ty.name AS data_type, c.max_length AS max_length_bytes, c.precision, c.scale, c.is_nullable, c.collation_name, c.is_identity, CONVERT(nvarchar(100),ic.seed_value) AS identity_seed, CONVERT(nvarchar(100),ic.increment_value) AS identity_increment, c.is_computed, cc.definition AS computed_definition, cc.is_persisted, dc.name AS default_name, dc.definition AS default_definition, c.is_rowguidcol, c.is_sparse FROM sys.columns c JOIN sys.objects o ON o.object_id=c.object_id JOIN sys.schemas s ON s.schema_id=o.schema_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id JOIN sys.schemas ts ON ts.schema_id=ty.schema_id LEFT JOIN sys.identity_columns ic ON ic.object_id=c.object_id AND ic.column_id=c.column_id LEFT JOIN sys.computed_columns cc ON cc.object_id=c.object_id AND cc.column_id=c.column_id LEFT JOIN sys.default_constraints dc ON dc.object_id=c.default_object_id WHERE o.is_ms_shipped=0 ORDER BY s.name,o.name,c.column_id",
        'indexes' => "SELECT i.object_id,i.index_id,i.name,i.type_desc,i.is_unique,i.is_primary_key,i.is_unique_constraint,i.is_disabled,i.has_filter,i.filter_definition,i.fill_factor,ds.name AS data_space FROM sys.indexes i JOIN sys.objects o ON o.object_id=i.object_id LEFT JOIN sys.data_spaces ds ON ds.data_space_id=i.data_space_id WHERE o.is_ms_shipped=0 ORDER BY i.object_id,i.index_id",
        'index_columns' => "SELECT ic.object_id,ic.index_id,ic.index_column_id,c.name AS column_name,ic.key_ordinal,ic.is_descending_key,ic.is_included_column,ic.partition_ordinal FROM sys.index_columns ic JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id JOIN sys.objects o ON o.object_id=ic.object_id WHERE o.is_ms_shipped=0 ORDER BY ic.object_id,ic.index_id,ic.index_column_id",
        'keys' => "SELECT object_id,parent_object_id,name,type_desc,unique_index_id FROM sys.key_constraints WHERE is_ms_shipped=0 ORDER BY parent_object_id,name",
        'foreign_keys' => "SELECT fk.object_id,fk.name,fk.parent_object_id,fk.referenced_object_id,fkc.constraint_column_id,pc.name AS child_column,rc.name AS parent_column,fk.delete_referential_action_desc,fk.update_referential_action_desc,fk.is_disabled,fk.is_not_trusted FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id=fk.object_id JOIN sys.columns pc ON pc.object_id=fkc.parent_object_id AND pc.column_id=fkc.parent_column_id JOIN sys.columns rc ON rc.object_id=fkc.referenced_object_id AND rc.column_id=fkc.referenced_column_id ORDER BY fk.object_id,fkc.constraint_column_id",
        'check_constraints' => "SELECT object_id,parent_object_id,parent_column_id,name,definition,is_disabled,is_not_trusted FROM sys.check_constraints WHERE is_ms_shipped=0 ORDER BY parent_object_id,name",
        'modules' => "SELECT m.object_id,m.definition,m.uses_ansi_nulls,m.uses_quoted_identifier,m.is_schema_bound,m.execute_as_principal_id FROM sys.sql_modules m JOIN sys.objects o ON o.object_id=m.object_id WHERE o.is_ms_shipped=0 ORDER BY m.object_id",
        'parameters' => "SELECT p.object_id,p.parameter_id,p.name,ty.name AS data_type,p.max_length,p.precision,p.scale,p.is_output,p.is_readonly,p.has_default_value,CONVERT(nvarchar(max),p.default_value) AS default_value FROM sys.parameters p JOIN sys.types ty ON ty.user_type_id=p.user_type_id JOIN sys.objects o ON o.object_id=p.object_id WHERE o.is_ms_shipped=0 ORDER BY p.object_id,p.parameter_id",
        'triggers' => "SELECT object_id,parent_id,name,is_disabled,is_instead_of_trigger FROM sys.triggers WHERE is_ms_shipped=0 ORDER BY parent_id,name",
        'sequences' => "SELECT object_id,name,CONVERT(nvarchar(100),start_value) AS start_value,CONVERT(nvarchar(100),increment) AS increment,CONVERT(nvarchar(100),minimum_value) AS minimum_value,CONVERT(nvarchar(100),maximum_value) AS maximum_value,is_cycling,is_cached FROM sys.sequences ORDER BY name",
        'synonyms' => "SELECT object_id,name,base_object_name FROM sys.synonyms ORDER BY name",
        'user_types' => "SELECT user_type_id,schema_id,name,system_type_id,max_length,precision,scale,is_nullable,is_table_type FROM sys.types WHERE is_user_defined=1 ORDER BY schema_id,name",
        'extended_properties' => "SELECT class,class_desc,major_id,minor_id,name,CONVERT(nvarchar(max),value) AS value FROM sys.extended_properties ORDER BY class,major_id,minor_id,name",
        'dependencies' => "SELECT referencing_id,referencing_minor_id,referenced_server_name,referenced_database_name,referenced_schema_name,referenced_entity_name,referenced_id,is_schema_bound_reference,is_caller_dependent,is_ambiguous FROM sys.sql_expression_dependencies ORDER BY referencing_id,referenced_entity_name",
        'permissions' => "SELECT class_desc,major_id,minor_id,USER_NAME(grantee_principal_id) AS grantee,USER_NAME(grantor_principal_id) AS grantor,type,permission_name,state_desc FROM sys.database_permissions ORDER BY grantee_principal_id,class,major_id,minor_id",
    ];
    $catalog = ['observed_at' => gmdate(DATE_ATOM), 'source' => 'Live SQL Server system catalogs', 'connection_mode' => $connectionMode, 'notes' => [
        'Only metadata is read; no application rows are exported.',
        'Visibility depends on the connected account permissions. NULL module definitions may indicate encryption or insufficient permission.',
        'Lengths are bytes; nvarchar/nchar character length is half the byte length. -1 means MAX.',
        'Business meaning and relationships without declared foreign keys cannot be proven from metadata.',
        'This inventory is documentation, not a restorable database backup or executable CREATE script.',
    ], 'errors' => []];
    foreach ($queries as $section => $sql) {
        try { $catalog[$section] = metadata($connection, $sql); }
        catch (Throwable $error) { $catalog[$section] = []; $catalog['errors'][$section] = $error->getMessage(); }
    }
    if (!$catalog['tables'] || !$catalog['columns']) { throw new RuntimeException('No live table/column metadata returned.'); }
    $lines = ['LIVE DATABASE SCHEMA REFERENCE', 'Generated: ' . $catalog['observed_at'], 'Database: ' . ($catalog['database'][0]['name'] ?? 'unknown'), ''];
    foreach ($catalog['notes'] as $note) { $lines[] = $note; }
    $lines[] = '';
    $columnsByObject = [];
    foreach ($catalog['columns'] as $column) { $columnsByObject[$column['object_id']][] = $column; }
    $lines[] = 'ALL TABLES AND COLUMN HEADERS';
    foreach ($catalog['tables'] as $table) {
        $columns = $columnsByObject[$table['object_id']] ?? [];
        $lines[] = $table['schema_name'] . '.' . $table['table_name'] . ' (' . count($columns) . ' columns)';
        $lines[] = '  ' . implode(', ', array_column($columns, 'column_name'));
    }
    foreach ($queries as $section => $sql) {
        $lines[] = "\n" . strtoupper(str_replace('_', ' ', $section)) . ' (' . count($catalog[$section]) . ')';
        foreach ($catalog[$section] as $row) {
            $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
    if ($catalog['errors']) { $lines[] = '\nINCOMPLETE SECTIONS: ' . json_encode($catalog['errors']); }
    $dir = dirname(__DIR__) . '/database_schema';
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) { throw new RuntimeException('Cannot create output directory.'); }
    foreach (['.htaccess' => "Require all denied\n", 'test_schema.json' => json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 'test_schema.txt' => implode("\n", $lines) . "\n"] as $file => $contents) {
        if (file_put_contents($dir . '/' . $file, $contents, LOCK_EX) === false) { throw new RuntimeException('Cannot save schema export.'); }
    }
    printf("Exported %d tables, %d columns, %d objects. Incomplete sections: %d.\n", count($catalog['tables']), count($catalog['columns']), count($catalog['objects']), count($catalog['errors']));
    echo 'VIEW DEFINITION: ' . ($catalog['visibility'][0]['can_view_database_definition'] ?? 'unknown') . "\n";
} catch (Throwable $error) {
    if (function_exists('sqlsrv_errors')) {
        foreach (sqlsrv_errors(SQLSRV_ERR_ERRORS) ?: [] as $diagnostic) {
            fwrite(STDERR, 'SQLSTATE: ' . $diagnostic['SQLSTATE'] . '; code: ' . $diagnostic['code'] . "\n");
        }
    }
    fwrite(STDERR, "Schema export failed. " . (str_contains($error->getMessage(), 'connection') ? 'Check database connection availability.' : $error->getMessage()) . "\n");
    exit(1);
}
