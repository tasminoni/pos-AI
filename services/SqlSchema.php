<?php
declare(strict_types=1);

/** One database-description file; no application-specific table or column names. */
final class SqlSchema
{
    public static function load(string $path): array
    {
        $text=@file_get_contents($path);
        if (!is_string($text)) { throw new RuntimeException('sql_schema.json could not be read.'); }
        return self::normalize(json_decode($text,true,512,JSON_THROW_ON_ERROR));
    }

    public static function normalize(array $input): array
    {
        $raw=$input['tables']??$input;
        if (!is_array($raw) || !$raw) { throw new InvalidArgumentException('The schema needs a nonempty tables object or table list.'); }
        $tables=[];
        foreach ($raw as $key=>$entry) {
            if (!is_array($entry)) { throw new InvalidArgumentException('Each table must contain columns.'); }
            $name=is_string($key)?$key:($entry['name']??$entry['table_name']??null);
            if (!is_string($name) || trim($name)==='') { throw new InvalidArgumentException('Each table needs a name.'); }
            $default=$entry['schema']??$input['default_schema']??'dbo';
            $qualified=str_contains($name,'.')?$name:$default.'.'.$name;
            $parts=explode('.',$qualified,2);
            if (count($parts)!==2 || !$parts[0] || !$parts[1] || preg_match('/[\x00-\x1f{}]/',$qualified)) {
                throw new InvalidArgumentException('Invalid table name.');
            }
            $columns=$entry['columns']??$entry;
            if (!is_array($columns) || !$columns) { throw new InvalidArgumentException('Each table needs a nonempty columns list or object.'); }
            $types=[]; $meta=$entry['column_meta']??[];
            foreach ($columns as $columnKey=>$column) {
                if (is_int($columnKey)) {
                    $columnName=is_string($column)?$column:($column['name']??$column['column_name']??null);
                    $descriptor=is_array($column)?$column:[];
                } else {
                    $columnName=$columnKey;
                    $descriptor=is_array($column)?$column:['type'=>is_string($column)?$column:'unknown'];
                }
                if (!is_string($columnName) || $columnName==='' || preg_match('/[\x00-\x1f{}]/',$columnName)) {
                    throw new InvalidArgumentException('Invalid column name.');
                }
                if (isset($types[$columnName])) { throw new InvalidArgumentException('Duplicate column name.'); }
                $type=$descriptor['type']??$descriptor['data_type']??'unknown';
                if (!is_string($type)) { throw new InvalidArgumentException('Column types must be strings.'); }
                $types[$columnName]=$type;
                if ($descriptor) { $meta[$columnName]=array_replace($meta[$columnName]??[],array_intersect_key($descriptor,array_flip(['description','sensitive','queryable','nullable']))); }
            }
            if (isset($tables[$qualified])) { throw new InvalidArgumentException('Duplicate table name.'); }
            $tables[$qualified]=['columns'=>$types,'column_meta'=>$meta,
                'queryable'=>$entry['queryable']??!preg_match('/(?:^|_)(?:users?|roles?|privileges?|tokens?|sessions?|credentials?|auth)(?:$|_)/i',$parts[1]),
                'description'=>$entry['description']??null,'date_column'=>$entry['date_column']??null,
                'primary_key'=>$entry['primary_key']??[]];
        }
        $access=$input['access']??[];
        if (!is_array($access) || !is_array($access['row_scope']??[])) { throw new InvalidArgumentException('Invalid schema access metadata.'); }
        foreach ($access['row_scope']??[] as $rule) {
            if (!is_array($rule) || !is_string($rule['column']??null) || !isset($rule['value']) && !isset($rule['values'])) {
                throw new InvalidArgumentException('Scope rules need a column and a value or values.');
            }
            $values=$rule['values']??[$rule['value']];
            if (!is_array($values) || !$values || count($values)>50) { throw new InvalidArgumentException('Invalid scope values.'); }
            foreach($values as $value){if(!is_string($value)&&!is_int($value)){throw new InvalidArgumentException('Scope values must be strings or integers.');}}
        }
        $timezone=$input['timezone']??'Asia/Dhaka';
        new DateTimeZone($timezone);
        return ['tables'=>$tables,'access'=>$access,'relationships'=>$input['relationships']??[],
            'notes'=>$input['notes']??[], 'timezone'=>$timezone,'observed_at'=>$input['observed_at']??null];
    }

    public static function allowedColumns(array $table): array
    {
        if (($table['queryable']??true)!==true) { return []; }
        return array_filter($table['columns'],static function($name)use($table):bool{
            $meta=$table['column_meta'][$name]??[];
            return ($meta['sensitive']??false)!==true && ($meta['queryable']??true)!==false
                && !preg_match('/password|passwd|secret|token|api[_-]?key|card[_-]?(?:number|no)|phone|mobile|email|address|contact|(?:^|_)pwd(?:$|_)/i',$name);
        },ARRAY_FILTER_USE_KEY);
    }

    public static function queryable(array $schema, string $name): array
    {
        $table=$schema['tables'][$name]??null;
        if (!is_array($table)) { return []; }
        $allowed=self::allowedColumns($table);
        foreach($schema['access']['row_scope']??[] as $rule){
            if (($rule['required']??false) && !self::columnName($allowed,$rule['column'])) { return []; }
        }
        return $allowed;
    }

    public static function tableName(array $schema,string $requested): ?string
    {
        foreach(array_keys($schema['tables']) as $name){if(strcasecmp($name,$requested)===0){return $name;}}
        return null;
    }

    public static function columnName(array $columns,string $requested): ?string
    {
        foreach(array_keys($columns) as $name){if(strcasecmp($name,$requested)===0){return $name;}}
        return null;
    }

    public static function dateColumn(array $table,array $preferred = []): ?string
    {
        $columns=self::allowedColumns($table);
        if (is_string($table['date_column']??null)) { return self::columnName($columns,$table['date_column']); }
        $candidates=[];
        foreach($columns as $name=>$type){
            if (preg_match('/^(date|datetime|datetime2|smalldatetime|datetimeoffset)(?:\W|$)/i',$type)
                || ($type==='unknown' && preg_match('/date|time|(?:created|updated)_?at$/i',$name))) { $candidates[]=$name; }
        }
        $matched=array_values(array_filter($candidates,static fn($name)=>in_array(strtolower($name),array_map('strtolower',$preferred),true)));
        return count($matched)===1?$matched[0]:(count($candidates)===1?$candidates[0]:null);
    }
}
