<?php
declare(strict_types=1);

/**
 * Local schema catalog. The TXT is a SQLite field dictionary, not proof of the
 * SQL Server schema. Only the explicit reporting allowlist is stored in JSON.
 */
final class SchemaService
{
    private const ALLOWED_COLUMNS = [
        'opdoheader' => ['zid', 'xshopno', 'xdornum', 'xworkingdate', 'xdate', 'xnetamt', 'xtotamt', 'xstatus', 'xstatusord', 'xtype', 'xnetret', 'xvatamt', 'xdiscamt', 'xcashamt', 'xcardamt'],
        'opdodetail' => ['zid', 'xshopno', 'xdornum', 'xrow', 'xitem', 'xqty', 'xqtyord', 'xnetamt', 'xlineamt', 'xretqty', 'xworkingdate'],
        'caitem' => ['zid', 'xshopno', 'xitem', 'xdesc', 'xcatitem', 'xitemdept'],
    ];

    private array $catalog;

    public function __construct(string $catalogPath)
    {
        $data = file_get_contents($catalogPath);
        if ($data === false) {
            throw new RuntimeException('Schema catalog could not be read.');
        }
        $catalog = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($catalog) || !isset($catalog['tables'], $catalog['relationships'])) {
            throw new RuntimeException('Invalid schema catalog.');
        }
        $this->catalog = $catalog;
    }

    public function catalog(): array
    {
        return $this->catalog;
    }

    public static function fromReference(string $text): array
    {
        $text = preg_replace('/\r\n?|\x{2028}/u', "\n", $text) ?? $text;
        preg_match_all('/(?m)^\d+\. Table: ([a-z][a-z0-9]*)\s*$/', $text, $sections, PREG_OFFSET_CAPTURE);
        if (count($sections[0]) === 0) {
            throw new RuntimeException('No table sections found in reference.');
        }

        $tables = [];
        foreach ($sections[0] as $i => $section) {
            $name = $sections[1][$i][0];
            $start = $section[1] + strlen($section[0]);
            $end = $sections[0][$i + 1][1] ?? (strpos($text, 'Main Local POS Table Relationships', $start) ?: strlen($text));
            $body = substr($text, $start, $end - $start);
            $description = self::cleanDescription(substr($body, 0, strpos($body, "\nField") ?: 0));
            $columns = [];
            preg_match_all('/(?m)^([a-z][a-z0-9]*)\n(TEXT|INTEGER|REAL)\n(Yes|No)\n/', $body, $fields, PREG_OFFSET_CAPTURE);
            foreach ($fields[0] as $j => $field) {
                $fieldName = $fields[1][$j][0];
                if (!in_array($fieldName, self::ALLOWED_COLUMNS[$name] ?? [], true)) {
                    continue;
                }
                $descriptionStart = $field[1] + strlen($field[0]);
                $descriptionEnd = $fields[0][$j + 1][1] ?? strlen($body);
                $columns[$fieldName] = [
                    'sqlite_type' => $fields[2][$j][0],
                    'mssql_type' => null,
                    'mssql_nullable' => null,
                    'nullable_documented' => $fields[3][$j][0] === 'Yes',
                    'description' => self::cleanDescription(substr($body, $descriptionStart, $descriptionEnd - $descriptionStart)),
                    'description_status' => 'unverified',
                    'metadata_status' => 'unverified',
                ];
            }
            $tables[$name] = [
                'description' => $description,
                'description_status' => 'unverified',
                'approved_for_gemini' => isset(self::ALLOWED_COLUMNS[$name]),
                'schema_name' => null,
                'metadata_status' => 'unverified',
                'columns' => $columns,
            ];
        }

        $relationships = self::parseRelationships($text, $tables);
        return [
            'catalog_version' => 1,
            'source' => 'Pos_Local_Database.txt',
            'source_kind' => 'SQLite mapping of SQL Server scripts',
            'verification' => ['status' => 'not_checked', 'checked_at' => null, 'mismatches' => []],
            'tables' => $tables,
            'relationships' => $relationships,
        ];
    }

    private static function cleanDescription(string $description): string
    {
        $description = preg_replace('/(?m)^\s*(?:\d+|Field|SQLite|Type|Nullable|Description)\s*$/', ' ', $description) ?? $description;
        return trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
    }

    private static function parseRelationships(string $text, array $tables): array
    {
        $start = strpos($text, 'Main Local POS Table Relationships');
        if ($start === false) {
            return [];
        }
        $end = strpos($text, 'Recommended Sync Order', $start);
        $body = substr($text, $start, ($end === false ? strlen($text) : $end) - $start);
        // The source is PDF-like text with split lines and sometimes a damaged arrow.
        $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
        $relationships = [];
        preg_match_all('/\b([a-z][a-z0-9]*)\.([a-z][a-z0-9]*)\s*(?:→|â†’|->)\s*([a-z][a-z0-9]*)\.([a-z][a-z0-9]*)/u', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (!isset($tables[$match[1]], $tables[$match[3]])) {
                continue;
            }
            $relationships[] = [
                'from_table' => $match[1], 'from_column' => $match[2],
                'to_table' => $match[3], 'to_column' => $match[4],
                'metadata_status' => 'unverified', 'source' => 'documented_logical_relationship',
            ];
        }
        // The remaining relationships in the document are verbal, not FK claims.
        if (str_contains($body, 'oppossigninlog') && str_contains($body, 'Same xshopno')) {
            $relationships[] = [
                'from_table' => 'oppossigninlog', 'to_table' => 'opdoheader',
                'join_description' => 'Same xshopno, xterminal, xshift and xdate/xworkingdate',
                'metadata_status' => 'unverified', 'source' => 'documented_logical_relationship',
            ];
        }
        if (str_contains($body, 'zbusiness.zid')) {
            $relationships[] = [
                'from_table' => 'zbusiness', 'from_column' => 'zid', 'to_table' => 'business_tables', 'to_column' => 'zid',
                'metadata_status' => 'unverified', 'source' => 'documented_logical_relationship',
            ];
        }
        return $relationships;
    }

    /** Only explicitly allowed, question-relevant metadata leaves PHP. */
    public function forQuestion(string $question): array
    {
        $question = strtolower($question);
        $sales = preg_match('/sales|sale|revenue|invoice|order|bill|bikri|bikroy|sell|\x{09AC}\x{09BF}\x{0995}\x{09CD}\x{09B0}\x{09BF}|\x{09B8}\x{09C7}\x{09B2}\x{09B8}|\x{0987}\x{09A8}\x{09AD}\x{09DF}\x{09C7}\x{09B8}|\x{09AC}\x{09BF}\x{09B2}/u', $question) === 1;
        $item = preg_match('/item|product|sku|category|detail|line item|invoice_lines|ponno|panno|\x{09AA}\x{09A3}\x{09CD}\x{09AF}|\x{0986}\x{0987}\x{099F}\x{09C7}\x{09AE}/u', $question) === 1;
        $names = $sales ? ['opdoheader'] : [];
        if ($item) {
            $names = ['opdoheader', 'opdodetail', 'caitem'];
        }
        $relevantColumns = [
            'opdoheader' => ['zid', 'xshopno', 'xdornum', 'xworkingdate', 'xnetamt', 'xstatusord', 'xtype'],
            'opdodetail' => ['zid', 'xshopno', 'xdornum', 'xrow', 'xitem', 'xqty', 'xnetamt'],
            'caitem' => ['zid', 'xshopno', 'xitem', 'xdesc', 'xcatitem'],
        ];
        $selected = [];
        foreach ($names as $name) {
            $table = $this->catalog['tables'][$name] ?? null;
            if (!$table || !$table['approved_for_gemini']) {
                continue;
            }
            $columns = [];
            foreach ($table['columns'] as $columnName => $column) {
                if (!in_array($columnName, $relevantColumns[$name], true)) {
                    continue;
                }
                $columns[$columnName] = [
                    'type' => $column['mssql_type'] ?? null,
                    'source_sqlite_type' => $column['sqlite_type'],
                    'description' => $column['description'],
                    'metadata_status' => $column['metadata_status'],
                    'description_status' => $column['description_status'],
                ];
            }
            $selected[$name] = ['schema_name' => $table['schema_name'], 'metadata_status' => $table['metadata_status'], 'columns' => $columns];
        }
        $relationships = array_values(array_filter($this->catalog['relationships'], static fn(array $r): bool =>
            isset($selected[$r['from_table']], $selected[$r['to_table']]) &&
            ($r['metadata_status'] ?? null) === 'observed_fk'));
        return ['catalog_version' => $this->catalog['catalog_version'], 'tables' => $selected, 'relationships' => $relationships];
    }

    /** Only observed MSSQL fields are eligible for the chat planning prompt. */
    public function verifiedForQuestion(string $question): array
    {
        $subset = $this->forQuestion($question);
        foreach ($subset['tables'] as $name => &$table) {
            if (($table['metadata_status'] ?? null) !== 'observed' || !is_string($table['schema_name'] ?? null)) {
                unset($subset['tables'][$name]);
                continue;
            }
            foreach ($table['columns'] as $columnName => $column) {
                if (($column['metadata_status'] ?? null) !== 'observed') {
                    unset($table['columns'][$columnName]);
                } else {
                    unset($table['columns'][$columnName]['source_sqlite_type']);
                    if (($column['description_status'] ?? null) !== 'verified') {
                        unset($table['columns'][$columnName]['description'],
                            $table['columns'][$columnName]['description_status']);
                    }
                }
            }
            if (!$table['columns']) {
                unset($subset['tables'][$name]);
            }
        }
        unset($table);
        $subset['relationships'] = array_values(array_filter($subset['relationships'],
            static fn(array $relationship): bool => isset($subset['tables'][$relationship['from_table']],
                $subset['tables'][$relationship['to_table']])));
        return $subset;
    }

    /** Read-only SQL Server catalog inspection. Never queries application rows. */
    public function verifyAgainstMssql($connection): array
    {
        if ($connection === null || !function_exists('sqlsrv_query')) {
            throw new RuntimeException('SQLSRV connection unavailable for metadata verification.');
        }
        $sql = 'SELECT s.name AS schema_name, t.name AS table_name, c.name AS column_name, '
            . 'ty.name AS data_type, c.max_length, c.precision, c.scale, c.is_nullable '
            . 'FROM sys.tables AS t JOIN sys.schemas AS s ON s.schema_id = t.schema_id '
            . 'JOIN sys.columns AS c ON c.object_id = t.object_id '
            . 'JOIN sys.types AS ty ON ty.user_type_id = c.user_type_id '
            . 'WHERE t.name IN (' . implode(', ', array_fill(0, count($this->catalog['tables']), '?')) . ') '
            . 'ORDER BY s.name, t.name, c.column_id';
        $statement = sqlsrv_query($connection, $sql, array_keys($this->catalog['tables']), ['QueryTimeout' => 15]);
        if ($statement === false) {
            throw new RuntimeException('Read-only MSSQL metadata query failed.');
        }
        $observed = [];
        while (($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) !== null) {
            if ($row === false) {
                sqlsrv_free_stmt($statement);
                throw new RuntimeException('Read-only MSSQL metadata fetch failed.');
            }
            $observed[$row['table_name']][$row['schema_name']][$row['column_name']] = $row;
        }
        sqlsrv_free_stmt($statement);

        $mismatches = [];
        foreach ($this->catalog['tables'] as $tableName => &$table) {
            $schemas = $observed[$tableName] ?? [];
            if (count($schemas) !== 1) {
                $table['metadata_status'] = count($schemas) === 0 ? 'missing' : 'ambiguous_schema';
                $mismatches[] = ['object' => $tableName, 'reason' => $table['metadata_status']];
                continue;
            }
            $schemaName = array_key_first($schemas);
            $table['schema_name'] = $schemaName;
            $table['metadata_status'] = 'observed';
            foreach ($table['columns'] as $columnName => &$column) {
                $actual = $schemas[$schemaName][$columnName] ?? null;
                if ($actual === null) {
                    $column['metadata_status'] = 'missing';
                    $mismatches[] = ['object' => "$tableName.$columnName", 'reason' => 'missing_column'];
                    continue;
                }
                $type = strtolower((string)$actual['data_type']);
                $column['mssql_type'] = $type;
                $column['mssql_nullable'] = (bool)$actual['is_nullable'];
                $column['max_length'] = (int)$actual['max_length'];
                $column['precision'] = (int)$actual['precision'];
                $column['scale'] = (int)$actual['scale'];
                $compatible = self::compatibleType($column['sqlite_type'], $type);
                $nullable = $column['nullable_documented'] === $column['mssql_nullable'];
                $column['metadata_status'] = $compatible && $nullable ? 'observed' : 'mismatch';
                if (!$compatible) {
                    $mismatches[] = ['object' => "$tableName.$columnName", 'reason' => 'type_family_mismatch', 'actual_type' => $type];
                }
                if (!$nullable) {
                    $mismatches[] = ['object' => "$tableName.$columnName", 'reason' => 'nullability_mismatch'];
                }
            }
            unset($column);
        }
        unset($table);
        $fkSql = 'SELECT OBJECT_NAME(fkc.parent_object_id) AS child_table, pc.name AS child_column, '
            . 'OBJECT_NAME(fkc.referenced_object_id) AS parent_table, rc.name AS parent_column '
            . 'FROM sys.foreign_key_columns AS fkc '
            . 'JOIN sys.columns AS pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id '
            . 'JOIN sys.columns AS rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id';
        $fkStatement = sqlsrv_query($connection, $fkSql, [], ['QueryTimeout' => 15]);
        if ($fkStatement === false) {
            throw new RuntimeException('Read-only MSSQL relationship metadata query failed.');
        }
        $foreignKeys = [];
        while (($fk = sqlsrv_fetch_array($fkStatement, SQLSRV_FETCH_ASSOC)) !== null) {
            if ($fk === false) {
                sqlsrv_free_stmt($fkStatement);
                throw new RuntimeException('Read-only MSSQL relationship metadata fetch failed.');
            }
            $foreignKeys[] = $fk;
        }
        sqlsrv_free_stmt($fkStatement);
        foreach ($this->catalog['relationships'] as &$relationship) {
            $relationship['metadata_status'] = 'unverified_no_declared_fk';
            if (!isset($relationship['from_column'], $relationship['to_column'])) {
                continue;
            }
            foreach ($foreignKeys as $fk) {
                $forward = $fk['parent_table'] === $relationship['from_table']
                    && $fk['parent_column'] === $relationship['from_column']
                    && $fk['child_table'] === $relationship['to_table']
                    && $fk['child_column'] === $relationship['to_column'];
                $reverse = $fk['parent_table'] === $relationship['to_table']
                    && $fk['parent_column'] === $relationship['to_column']
                    && $fk['child_table'] === $relationship['from_table']
                    && $fk['child_column'] === $relationship['from_column'];
                if ($forward || $reverse) {
                    $relationship['metadata_status'] = 'observed_fk';
                    break;
                }
            }
        }
        unset($relationship);
        // No declared FK is not a data mismatch: the TXT lists logical joins.
        $this->catalog['verification'] = [
            'status' => $mismatches ? 'mismatches_found' : 'metadata_observed',
            'checked_at' => gmdate('c'),
            'mismatches' => $mismatches,
        ];
        return $this->catalog;
    }

    private static function compatibleType(string $sqliteType, string $mssqlType): bool
    {
        return in_array($mssqlType, match ($sqliteType) {
            'INTEGER' => ['int', 'bigint', 'smallint', 'tinyint'],
            'REAL' => ['numeric', 'decimal', 'float', 'real', 'money', 'smallmoney'],
            default => ['varchar', 'nvarchar', 'char', 'nchar', 'text', 'ntext', 'date', 'datetime', 'datetime2', 'smalldatetime', 'time', 'datetimeoffset', 'uniqueidentifier'],
        }, true);
    }
}
