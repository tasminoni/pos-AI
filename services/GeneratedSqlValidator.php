<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlValidator.php';

/** Parses a deliberately limited SELECT grammar and scopes every table source. */
final class GeneratedSqlValidator
{
    private array $tokens = [];
    private int $position = 0;
    private array $bindings = [];
    private array $sources = [];
    private array $references = [];
    private array $outputAliases = [];
    private array $dateFilters = [];
    private int $depth = 0;
    private array $aggregateAliases = [];

    public function __construct(private array $schema, private int $maxRows = 100) {}

    public static function allowedColumns(string $table, array $columns): array
    {
        if (preg_match('/users|roles|privilege|token|signin|signout|multiplecard|reprint|_20\d{6}|_deleted?$/i', $table)
            || !isset($columns['zid'])) { return []; }
        return array_filter($columns, static fn($name) => !preg_match(
            '/pass|email|phone|mobile|cardno|cardnum|token|secret|address|contact|^xname$|^xperson/i', $name), ARRAY_FILTER_USE_KEY);
    }

    public function compile(string $sql, ReportScope $scope, array $filters = [], int $offset = 0): array
    {
        if ($offset < 0 || $offset > 10000) { $this->reject(); }
        $this->tokens = []; $this->position = 0; $this->bindings = [];
        $this->sources = []; $this->references = []; $this->outputAliases = [];
        $this->depth = 0;
        $this->aggregateAliases = [];
        $this->dateFilters = [];
        if (isset($filters['date_start']) || isset($filters['date_end_inclusive'])) {
            foreach (['date_start','date_end_inclusive'] as $key) {
                $value=$filters[$key]??null;
                if (!is_string($value)) { $this->reject(); }
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                if (!$date || $date->format('Y-m-d')!==$value) { $this->reject(); }
                $this->dateFilters[$key]=$date;
            }
            if ($this->dateFilters['date_end_inclusive'] < $this->dateFilters['date_start']) { $this->reject(); }
        }
        if (strlen($sql) > 12000) { $this->reject(); }
        $cursorOffset = 0;
        while ($cursorOffset < strlen($sql)) {
            if (!preg_match('/\G\s*(?:(N?\x27(?:[^\x27]|\x27\x27)*\x27)|(\[(?:[^\]\r\n]|\]\])+\])|([A-Za-z_][A-Za-z0-9_]*)|([0-9]+(?:\.[0-9]+)?)|(<>|!=|<=|>=|[(),.*+\/%=<>-]))/Ai', $sql, $match, 0, $cursorOffset)) {
                if (trim(substr($sql, $cursorOffset)) === '') { break; }
                $this->reject();
            }
            $cursorOffset += strlen($match[0]);
            if (($match[1] ?? '') !== '') {
                $literal = preg_replace('/^N/i', '', $match[1]);
                $this->tokens[] = ['string', str_replace("''", "'", substr($literal, 1, -1))];
            } elseif (($match[2] ?? '') !== '') { $this->tokens[] = ['id', str_replace(']]',']',substr($match[2], 1, -1))]; }
            elseif (($match[3] ?? '') !== '') { $this->tokens[] = ['id', $match[3]]; }
            elseif (($match[4] ?? '') !== '') { $this->tokens[] = ['number', $match[4]]; }
            else { $this->tokens[] = ['symbol', $match[5]]; }
        }
        $this->expect('SELECT');
        if (count($this->tokens) > 2000) { $this->reject(); }
        $distinct = $this->take('DISTINCT') ? 'DISTINCT ' : '';
        $limit = max(1, min(500, $this->maxRows));
        $requestedTop = false;
        if ($this->take('TOP')) {
            $paren = $this->take('('); $number = $this->next();
            if ($number[0] !== 'number' || !ctype_digit($number[1]) || (int)$number[1] < 1) { $this->reject(); }
            $limit = min($limit, (int)$number[1]);
            $requestedTop = true;
            if ($paren) { $this->expect(')'); }
        }
        $select = [];
        do {
            $expression = $this->expression();
            if ($this->take('AS')) {
                $alias = $this->identifier();
                if (isset($this->outputAliases[strtolower($alias)])) { $this->reject(); }
                $this->outputAliases[strtolower($alias)] = true;
                if (preg_match('/\b(?:SUM|AVG|COUNT|COUNT_BIG|MIN|MAX)\(/',$expression)) { $this->aggregateAliases[]=$alias; }
                $expression .= ' AS ' . $this->quote($alias);
            }
            $select[] = $expression;
        } while ($this->take(','));
        $this->expect('FROM');
        $from = $this->source($scope);
        while (in_array($this->peek(), ['JOIN', 'INNER', 'LEFT'], true)) {
            $kind = 'INNER';
            if ($this->take('LEFT')) { $kind = 'LEFT'; $this->take('OUTER'); }
            else { $this->take('INNER'); }
            $this->expect('JOIN');
            $source = $this->source($scope); $this->expect('ON');
            $from .= " $kind JOIN $source ON " . $this->expression();
        }
        $where = $this->take('WHERE') ? ' WHERE ' . $this->expression() : '';
        $group = '';
        if ($this->take('GROUP')) { $this->expect('BY'); $group = ' GROUP BY ' . $this->expressionList(); }
        $having = $this->take('HAVING') ? ' HAVING ' . $this->expression() : '';
        $order = '';
        if ($this->take('ORDER')) {
            $this->expect('BY'); $parts = [];
            do {
                $part = $this->expression();
                if ($this->take('ASC')) { $part .= ' ASC'; }
                elseif ($this->take('DESC')) { $part .= ' DESC'; }
                $parts[] = $part;
            } while ($this->take(','));
            $order = ' ORDER BY ' . implode(', ', $parts);
        }
        if ($this->position !== count($this->tokens)) { $this->reject(); }
        foreach ($this->references as [$alias, $column]) {
            if (!isset($this->sources[strtolower($alias)][$column])) { $this->reject(); }
        }
        if ($offset > 0 && ($requestedTop || $order === '')) { $this->reject(); }
        $parsed = 'SELECT ' . $distinct . 'TOP (' . ($offset + $limit + ($requestedTop?0:1)) . ') ' . implode(', ', $select)
            . ' FROM ' . $from . $where . $group . $having . $order;
        $params = [];
        $parsed = preg_replace_callback('/\{B([0-9]+)\}/', function ($match) use (&$params) {
            $params[] = $this->bindings[(int)$match[1]]; return '?';
        }, $parsed);
        return ['sql' => $parsed, 'params' => $params, 'limit' => $limit, 'aggregate_aliases'=>$this->aggregateAliases,
            'offset'=>$offset,'can_page'=>!$requestedTop && $order !== ''];
    }

    private function source(ReportScope $scope): string
    {
        $schema = $this->identifier(); $this->expect('.'); $table = $this->identifier();
        $this->expect('AS'); $alias = $this->identifier();
        $columns = $this->schema['tables']["$schema.$table"]['columns'] ?? [];
        $allowed = self::allowedColumns($table, $columns);
        if (!$allowed || count($this->sources) >= 8 || isset($this->sources[strtolower($alias)])) { $this->reject(); }
        $this->sources[strtolower($alias)] = $allowed;
        $predicate = '[zid] = ' . $this->bind($scope->zid);
        if (isset($allowed['xshopno'])) {
            $marks = array_map(fn($shop) => $this->bind($shop), $scope->shopnos);
            $predicate .= ' AND [xshopno] IN (' . implode(', ', $marks) . ')';
        }
        if ($this->dateFilters && count($this->sources) === 1) {
            $dateColumn = isset($allowed['xworkingdate']) ? 'xworkingdate' : (isset($allowed['xdate']) ? 'xdate' : null);
            if ($dateColumn === null) { $this->reject(); }
            $predicate .= ' AND '.$this->quote($dateColumn).' >= '.$this->bind($this->dateFilters['date_start']->format('Y-m-d'))
                .' AND '.$this->quote($dateColumn).' < '.$this->bind($this->dateFilters['date_end_inclusive']->modify('+1 day')->format('Y-m-d'));
        }
        return '(SELECT ' . implode(', ', array_map(fn($col) => $this->quote($col), array_keys($allowed)))
            . ' FROM ' . $this->quote($schema) . '.' . $this->quote($table)
            . ' WHERE ' . $predicate . ') AS ' . $this->quote($alias);
    }

    private function expression(int $minimum = 0): string
    {
        if (++$this->depth > 32) { $this->reject(); }
        if ($this->take('NOT')) { $left = '(NOT ' . $this->expression(3) . ')'; }
        elseif ($this->take('-')) { $left = '(-' . $this->expression(6) . ')'; }
        elseif ($this->take('(')) { $left = '(' . $this->expression() . ')'; $this->expect(')'); }
        else {
            $token = $this->next();
            if ($token[0] === 'string') { $left = $this->bind($token[1]); }
            elseif ($token[0] === 'number') { $left = $this->bind(str_contains($token[1], '.') ? $token[1] : (int)$token[1]); }
            elseif ($token[0] === 'id') {
                $name = strtoupper($token[1]);
                if ($this->take('.')) {
                    $column = $this->identifier(); $this->references[] = [$token[1], $column];
                    $left = $this->quote($token[1]) . '.' . $this->quote($column);
                } elseif ($name==='CASE') {
                    $clauses=[];
                    while ($this->take('WHEN')) {
                        if (count($clauses)>=10) { $this->reject(); }
                        $condition=$this->expression(); $this->expect('THEN'); $value=$this->expression();
                        $clauses[]="WHEN $condition THEN $value";
                    }
                    if (!$clauses) { $this->reject(); }
                    $otherwise=$this->take('ELSE')?' ELSE '.$this->expression():'';
                    $this->expect('END'); $left='CASE '.implode(' ',$clauses).$otherwise.' END';
                } elseif ($this->take('(')) {
                    if (!in_array($name, ['SUM','AVG','COUNT','COUNT_BIG','MIN','MAX','YEAR','MONTH','DAY','DATEPART','COALESCE','ISNULL','NULLIF','ROUND','ABS','CAST','LEN','LOWER','UPPER','LTRIM','RTRIM','CEILING','FLOOR','SUBSTRING'], true)) { $this->reject(); }
                    if ($name === 'CAST') {
                        $arg = $this->expression(); $this->expect('AS'); $type = $this->identifier();
                        if (!in_array(strtolower($type), ['date','datetime','int','bigint','decimal','numeric','varchar'], true)) { $this->reject(); }
                        if ($this->take('(')) {
                            $sizes = [];
                            do { $size = $this->next(); if ($size[0] !== 'number' || !ctype_digit($size[1]) || (int)$size[1] > 4000) { $this->reject(); } $sizes[] = $size[1]; } while ($this->take(','));
                            $this->expect(')'); $type .= '(' . implode(',', $sizes) . ')';
                        }
                        $this->expect(')'); $left = "CAST($arg AS $type)";
                    } elseif ($name === 'DATEPART') {
                        $part = strtolower($this->identifier());
                        if (!in_array($part, ['year','month','day','quarter','week'], true)) { $this->reject(); }
                        $this->expect(','); $arg = $this->expression(); $this->expect(')');
                        $left = "DATEPART($part, $arg)";
                    } elseif (in_array($name,['COUNT','COUNT_BIG'],true) && $this->take('*')) { $this->expect(')'); $left = "$name(*)"; }
                    else {
                        $distinct = $this->take('DISTINCT') ? 'DISTINCT ' : '';
                        $args = $this->expressionList(); $this->expect(')'); $left = "$name($distinct$args)";
                    }
                } elseif ($name === 'NULL') { $left = 'NULL'; }
                elseif (isset($this->outputAliases[strtolower($token[1])])) { $left = $this->quote($token[1]); }
                else { $this->reject(); }
            } else { $this->reject(); }
        }
        $precedence = ['OR'=>1,'AND'=>2,'='=>3,'!='=>3,'<>'=>3,'<'=>3,'>'=>3,'<='=>3,'>='=>3,'IS'=>3,'IN'=>3,'LIKE'=>3,'BETWEEN'=>3,'NOT'=>3,'+'=>4,'-'=>4,'*'=>5,'/'=>5,'%'=>5];
        while (($precedence[$this->peek()] ?? -1) >= $minimum) {
            $operator = $this->next()[1]; $upper = strtoupper($operator); $level = $precedence[$upper];
            $negation='';
            if ($upper==='NOT') {
                $upper=$this->next()[1]; $upper=strtoupper($upper); $negation='NOT ';
                if (!in_array($upper,['IN','LIKE','BETWEEN'],true)) { $this->reject(); }
            }
            if ($upper === 'IS') { $not = $this->take('NOT') ? ' NOT' : ''; $this->expect('NULL'); $left = "($left IS$not NULL)"; }
            elseif ($upper === 'IN') { $this->expect('('); $values = $this->expressionList(); $this->expect(')'); $left = "($left {$negation}IN ($values))"; }
            elseif ($upper === 'BETWEEN') { $start = $this->expression(4); $this->expect('AND'); $end = $this->expression(4); $left = "($left {$negation}BETWEEN $start AND $end)"; }
            else { $right = $this->expression($level + 1); $left = "($left $negation$upper $right)"; }
        }
        $this->depth--;
        return $left;
    }

    private function expressionList(): string
    {
        $parts = []; do { $parts[] = $this->expression(); } while ($this->take(','));
        return implode(', ', $parts);
    }
    private function quote(string $name): string { return '[' . str_replace(']',']]', $name) . ']'; }
    private function bind(mixed $value): string { $index = count($this->bindings); $this->bindings[] = $value; return '{B' . $index . '}'; }
    private function peek(): string { return strtoupper($this->tokens[$this->position][1] ?? ''); }
    private function next(): array { if (!isset($this->tokens[$this->position])) { $this->reject(); } return $this->tokens[$this->position++]; }
    private function take(string $value): bool { if (($this->tokens[$this->position][0] ?? '') === 'string' || $this->peek() !== $value) { return false; } $this->position++; return true; }
    private function expect(string $value): void { if (!$this->take($value)) { $this->reject(); } }
    private function identifier(): string { $token = $this->next(); if ($token[0] !== 'id') { $this->reject(); } return $token[1]; }
    private function reject(): never { throw new QueryValidationException('generated_sql_not_in_supported_read_only_grammar'); }
}
