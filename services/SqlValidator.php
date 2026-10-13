<?php
declare(strict_types=1);

require_once __DIR__ . '/ReportPlanCompiler.php';

final class QueryValidationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Query plan was rejected.');
    }
}

/**
 * Allowlisted report AST compiler. No caller-supplied SQL, table names,
 * expressions, predicates, joins, functions, or nested queries are accepted.
 */
final class SqlValidator
{
    private int $maxRows;
    private int $maxOffset;
    private int $maxDetailDays;

    public function __construct(private array $catalog, private array $rules, array $limits = [])
    {
        $this->maxRows = max(1, min(500, (int)($limits['max_rows'] ?? 100)));
        $this->maxOffset = max(0, min(100000, (int)($limits['max_offset'] ?? 10000)));
        $this->maxDetailDays = max(1, min(366, (int)($limits['max_detail_days'] ?? 366)));
    }

    public function validateAndCompile(array $plan, ReportScope $scope): array
    {
        $keys = array_keys($plan);
        sort($keys);
        $baseKeys = ['date_end_exclusive', 'date_start', 'group_by', 'offset', 'page_size', 'report'];
        $filteredKeys = ['date_end_exclusive', 'date_start', 'group_by', 'offset', 'page_size', 'report', 'shop_filter'];
        if ($keys !== $baseKeys && $keys !== $filteredKeys) {
            throw new QueryValidationException('invalid_shape');
        }
        if (array_key_exists('shop_filter', $plan)) {
            if ($plan['shop_filter'] !== null) {
                if (!is_string($plan['shop_filter'])) {
                    throw new QueryValidationException('invalid_shop_filter');
                }
                try { $scope = $scope->forShop($plan['shop_filter']); }
                catch (InvalidArgumentException) { throw new QueryValidationException('unauthorized_shop_filter'); }
            }
        }
        $report = $plan['report'];
        $group = $plan['group_by'];
        if (!is_string($report) || !in_array($report, ['sales_amount', 'product_sales_amount', 'invoice_list', 'invoice_lines'], true)
            || !is_string($group) || !in_array($group, ['none', 'year', 'month', 'day', 'shop', 'product'], true)
            || !is_int($plan['page_size']) || $plan['page_size'] < 1 || $plan['page_size'] > $this->maxRows
            || !is_int($plan['offset']) || $plan['offset'] < 0 || $plan['offset'] > $this->maxOffset) {
            throw new QueryValidationException('invalid_value');
        }
        $allHistory = $plan['date_start'] === null && $plan['date_end_exclusive'] === null;
        if (($plan['date_start'] === null) !== ($plan['date_end_exclusive'] === null)) {
            throw new QueryValidationException('invalid_date_scope');
        }
        $span = null;
        if (!$allHistory) {
            if (!is_string($plan['date_start']) || !is_string($plan['date_end_exclusive'])) {
                throw new QueryValidationException('invalid_date_scope');
            }
            $start = $this->date($plan['date_start']);
            $end = $this->date($plan['date_end_exclusive']);
            $span = (int)$start->diff($end)->format('%r%a');
            if ($span < 1) {
                throw new QueryValidationException('invalid_date_scope');
            }
        }

        if ($report === 'sales_amount') {
            $this->approvedTable('opdoheader',
                ['zid', 'xshopno', 'xdornum', 'xworkingdate', 'xnetamt', 'xstatusord', 'xtype']);
            if ($allHistory && $group === 'day') {
                throw new QueryValidationException('unbounded_daily_grouping');
            }
            if (!$allHistory && $span > 366 * 6) {
                throw new QueryValidationException('date_span_exceeded');
            }
            if ($group === 'none' && ($plan['page_size'] !== 1 || $plan['offset'] !== 0)) {
                throw new QueryValidationException('invalid_pagination');
            }
            try {
                $compiled = (new ReportPlanCompiler($this->catalog, $this->rules))->compile([
                    'metric_id' => 'sales_amount', 'group_by' => $group,
                    'date_start' => $plan['date_start'], 'date_end_exclusive' => $plan['date_end_exclusive'],
                ], $scope);
            } catch (Throwable) {
                throw new QueryValidationException('unverified_sales_definition');
            }
            $columns = match ($group) {
                'year' => [['name' => 'year', 'type' => 'integer']],
                'month' => [['name' => 'year', 'type' => 'integer'], ['name' => 'month', 'type' => 'integer']],
                'day' => [['name' => 'day', 'type' => 'date']],
                'shop' => [['name' => 'shop', 'type' => 'string']],
                default => [],
            };
            $columns[] = ['name' => 'sales_amount', 'type' => 'decimal'];
            $params = array_column($compiled['bind_parameters'], 'value');
            $sql = $compiled['sql'];
            if ($group !== 'none') {
                $sql .= ' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
                $params[] = $plan['offset'];
                $params[] = $plan['page_size'] + 1;
            }
            return $this->result($report, $sql, $params, $columns, $plan, $scope);
        }

        if ($report === 'product_sales_amount') {
            if ($group !== 'product' || (!$allHistory && $span > 366 * 6)) {
                throw new QueryValidationException('product_scope_invalid');
            }
            $compiler = new ReportPlanCompiler($this->catalog, $this->rules);
            if (!$compiler->canCompileSales()) {
                throw new QueryValidationException('unverified_sales_definition');
            }
            $metric = $this->rules['definitions']['product_sales_amount'] ?? [];
            if (($metric['status'] ?? null) !== 'verified' || ($metric['source_table'] ?? null) !== 'opdodetail'
                || ($metric['source_column'] ?? null) !== 'xnetamt' || ($metric['aggregation'] ?? null) !== 'SUM'
                || ($metric['group_column'] ?? null) !== 'xitem') {
                throw new QueryValidationException('unverified_product_definition');
            }
            $this->approvedJoin();
            $header = $this->approvedTable('opdoheader',
                ['zid', 'xshopno', 'xdornum', 'xworkingdate', 'xnetamt', 'xstatusord', 'xtype']);
            $detail = $this->approvedTable('opdodetail',
                ['zid', 'xshopno', 'xdornum', 'xitem', 'xnetamt']);
            $this->approvedType('opdodetail', 'xnetamt', ['numeric', 'decimal', 'money', 'smallmoney']);
            $statuses = $this->approvedValues($this->rules['definitions']['invoice_status']['included_values'] ?? null);
            $types = $this->approvedValues($this->rules['definitions']['returns']['sale_type_values'] ?? null);
            $shopMarks = implode(', ', array_fill(0, count($scope->shopnos), '?'));
            $statusMarks = implode(', ', array_fill(0, count($statuses), '?'));
            $typeMarks = implode(', ', array_fill(0, count($types), '?'));
            $sql = 'SELECT [d].[xitem] AS [product], SUM([d].[xnetamt]) AS [sales_amount] '
                . "FROM $header AS [h] INNER JOIN $detail AS [d] "
                . 'ON [d].[zid] = [h].[zid] AND [d].[xshopno] = [h].[xshopno] '
                . 'AND [d].[xdornum] = [h].[xdornum] WHERE '
                . ($allHistory ? '' : '[h].[xworkingdate] >= ? AND [h].[xworkingdate] < ? AND ')
                . "[h].[zid] = ? AND [h].[xshopno] IN ($shopMarks) "
                . "AND [d].[zid] = ? AND [d].[xshopno] IN ($shopMarks) "
                . "AND [h].[xstatusord] IN ($statusMarks) AND [h].[xtype] IN ($typeMarks) "
                . 'GROUP BY [d].[xitem] ORDER BY [d].[xitem] OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
            $params = $allHistory ? [] : [$plan['date_start'], $plan['date_end_exclusive']];
            $params = [...$params, $scope->zid, ...$scope->shopnos, $scope->zid, ...$scope->shopnos,
                ...$statuses, ...$types, $plan['offset'], $plan['page_size'] + 1];
            return $this->result($report, $sql, $params,
                [['name' => 'product', 'type' => 'string'], ['name' => 'sales_amount', 'type' => 'decimal']], $plan, $scope);
        }

        if ($group !== 'none' || $allHistory || $span > $this->maxDetailDays) {
            throw new QueryValidationException('detail_scope_invalid');
        }
        if (!in_array($this->rules['definitions']['working_date']['status'] ?? null, ['verified', 'configured'], true)
            || ($this->rules['definitions']['working_date']['source_column'] ?? null) !== 'xworkingdate') {
            throw new QueryValidationException('unverified_working_date');
        }
        $header = $this->approvedTable('opdoheader',
            ['zid', 'xshopno', 'xdornum', 'xworkingdate', 'xnetamt', 'xstatusord', 'xtype']);
        $this->approvedType('opdoheader', 'xworkingdate', ['date', 'datetime', 'datetime2', 'smalldatetime']);
        $this->approvedType('opdoheader', 'xnetamt', ['numeric', 'decimal', 'money', 'smallmoney']);
        $shopMarks = implode(', ', array_fill(0, count($scope->shopnos), '?'));
        $baseWhere = "[h].[xworkingdate] >= ? AND [h].[xworkingdate] < ? "
            . "AND [h].[zid] = ? AND [h].[xshopno] IN ($shopMarks)";
        $params = [$plan['date_start'], $plan['date_end_exclusive'], $scope->zid, ...$scope->shopnos];

        if ($report === 'invoice_list') {
            $sql = 'SELECT [h].[xdornum] AS [invoice_no], [h].[xworkingdate] AS [working_date], '
                . '[h].[xshopno] AS [shop], [h].[xnetamt] AS [net_amount], '
                . '[h].[xstatusord] AS [invoice_status], [h].[xtype] AS [document_type] '
                . "FROM $header AS [h] WHERE $baseWhere "
                . 'ORDER BY [h].[xworkingdate], [h].[xshopno], [h].[xdornum] '
                . 'OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
            $columns = [
                ['name' => 'invoice_no', 'type' => 'string'], ['name' => 'working_date', 'type' => 'date'],
                ['name' => 'shop', 'type' => 'string'], ['name' => 'net_amount', 'type' => 'decimal'],
                ['name' => 'invoice_status', 'type' => 'string'], ['name' => 'document_type', 'type' => 'string'],
            ];
        } else {
            $this->approvedJoin();
            $detail = $this->approvedTable('opdodetail',
                ['zid', 'xshopno', 'xdornum', 'xrow', 'xitem', 'xqty', 'xnetamt']);
            $this->approvedType('opdodetail', 'xnetamt', ['numeric', 'decimal', 'money', 'smallmoney']);
            $this->approvedType('opdodetail', 'xqty', ['numeric', 'decimal', 'float', 'real']);
            $sql = 'SELECT [h].[xdornum] AS [invoice_no], [h].[xworkingdate] AS [working_date], '
                . '[h].[xshopno] AS [shop], [d].[xrow] AS [line_no], [d].[xitem] AS [item], '
                . '[d].[xqty] AS [quantity], [d].[xnetamt] AS [line_net_amount] '
                . "FROM $header AS [h] INNER JOIN $detail AS [d] "
                . 'ON [d].[zid] = [h].[zid] AND [d].[xshopno] = [h].[xshopno] '
                . "AND [d].[xdornum] = [h].[xdornum] WHERE $baseWhere "
                . "AND [d].[zid] = ? AND [d].[xshopno] IN ($shopMarks) "
                . 'ORDER BY [h].[xworkingdate], [h].[xshopno], [h].[xdornum], [d].[xrow] '
                . 'OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
            $params[] = $scope->zid;
            array_push($params, ...$scope->shopnos);
            $columns = [
                ['name' => 'invoice_no', 'type' => 'string'], ['name' => 'working_date', 'type' => 'date'],
                ['name' => 'shop', 'type' => 'string'], ['name' => 'line_no', 'type' => 'integer'],
                ['name' => 'item', 'type' => 'string'], ['name' => 'quantity', 'type' => 'number'],
                ['name' => 'line_net_amount', 'type' => 'decimal'],
            ];
        }
        $params[] = $plan['offset'];
        $params[] = $plan['page_size'] + 1;
        return $this->result($report, $sql, $params, $columns, $plan, $scope);
    }

    private function date(string $value): DateTimeImmutable
    {
        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        } catch (ValueError) {
            throw new QueryValidationException('invalid_date_scope');
        }
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new QueryValidationException('invalid_date_scope');
        }
        return $date;
    }

    private function approvedTable(string $name, array $columns): string
    {
        $table = $this->catalog['tables'][$name] ?? null;
        if (($table['approved_for_gemini'] ?? false) !== true || ($table['metadata_status'] ?? null) !== 'observed') {
            throw new QueryValidationException('unverified_table');
        }
        $schema = $table['schema_name'] ?? null;
        if (!is_string($schema) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $schema)) {
            throw new QueryValidationException('unauthorized_schema');
        }
        foreach ($columns as $column) {
            if (($table['columns'][$column]['metadata_status'] ?? null) !== 'observed') {
                throw new QueryValidationException('unverified_column');
            }
        }
        return "[$schema].[$name]";
    }

    private function approvedType(string $table, string $column, array $types): void
    {
        if (!in_array($this->catalog['tables'][$table]['columns'][$column]['mssql_type'] ?? null, $types, true)) {
            throw new QueryValidationException('unverified_column_type');
        }
    }

    private function approvedJoin(): void
    {
        $join = $this->rules['joins']['header_detail'] ?? [];
        if (($join['status'] ?? null) !== 'verified'
            || ($join['left_table'] ?? null) !== 'opdoheader'
            || ($join['right_table'] ?? null) !== 'opdodetail'
            || ($join['keys'] ?? null) !== [['zid', 'zid'], ['xshopno', 'xshopno'], ['xdornum', 'xdornum']]) {
            throw new QueryValidationException('unverified_join');
        }
    }

    private function approvedValues(mixed $values): array
    {
        if (!is_array($values) || !$values || count($values) > 20) {
            throw new QueryValidationException('unverified_business_values');
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 64) {
                throw new QueryValidationException('unverified_business_values');
            }
        }
        return array_values(array_unique($values));
    }

    private function result(string $report, string $sql, array $params, array $columns, array $plan, ReportScope $scope): array
    {
        return [
            'report' => $report,
            'sql' => $sql,
            'params' => $params,
            'columns' => $columns,
            'page_size' => $plan['page_size'],
            'offset' => $plan['offset'],
            'max_offset' => $this->maxOffset,
            'scope' => ['zid' => $scope->zid, 'shopnos' => $scope->shopnos],
            'date_scope' => ['start' => $plan['date_start'], 'end_exclusive' => $plan['date_end_exclusive'],
                'all_authorized_history' => $plan['date_start'] === null],
        ];
    }
}
