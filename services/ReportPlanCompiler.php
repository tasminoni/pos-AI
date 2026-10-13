<?php
declare(strict_types=1);

require_once __DIR__ . '/ReportScope.php';

/** Compiles only the reviewed sales template. This class never executes SQL. */
final class ReportPlanCompiler
{
    public function __construct(private array $catalog, private array $rules) {}

    public function canCompileSales(): bool
    {
        foreach ($this->rules['required_for_sales_sql'] ?? [] as $name) {
            if (!in_array($this->rules['definitions'][$name]['status'] ?? null, ['verified', 'configured'], true)) {
                return false;
            }
        }
        $required = $this->rules['required_for_sales_sql'] ?? [];
        sort($required);
        if ($required !== ['aggregation', 'invoice_status', 'returns', 'sales_amount', 'working_date']) {
            return false;
        }
        $table = $this->catalog['tables']['opdoheader'] ?? null;
        if (($table['metadata_status'] ?? null) !== 'observed' || !is_string($table['schema_name'] ?? null)) {
            return false;
        }
        foreach (['zid', 'xshopno', 'xdornum', 'xnetamt', 'xstatusord', 'xtype', 'xworkingdate'] as $column) {
            if (($table['columns'][$column]['metadata_status'] ?? null) !== 'observed') {
                return false;
            }
        }
        if (!in_array($table['columns']['xnetamt']['mssql_type'] ?? null,
            ['numeric', 'decimal', 'money', 'smallmoney'], true)) {
            return false;
        }
        if (!in_array($table['columns']['xworkingdate']['mssql_type'] ?? null,
            ['date', 'datetime', 'datetime2', 'smalldatetime'], true)) {
            return false;
        }
        return true;
    }

    public function compile(array $plan, ReportScope $scope): array
    {
        if (!$this->canCompileSales()) {
            throw new RuntimeException('Sales rules or MSSQL metadata are not verified.');
        }
        if ($plan['metric_id'] !== 'sales_amount') {
            throw new UnexpectedValueException('Unsupported metric.');
        }
        $allHistory = ($plan['date_start'] ?? null) === null && ($plan['date_end_exclusive'] ?? null) === null;
        if (!$allHistory) {
            if (!is_string($plan['date_start'] ?? null) || !is_string($plan['date_end_exclusive'] ?? null)) {
                throw new UnexpectedValueException('Report period is invalid.');
            }
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $plan['date_start'], new DateTimeZone('UTC'));
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $plan['date_end_exclusive'], new DateTimeZone('UTC'));
            if (!$start || !$end || $start->format('Y-m-d') !== $plan['date_start']
                || $end->format('Y-m-d') !== $plan['date_end_exclusive']
                || (int)$start->diff($end)->format('%r%a') < 1
                || (int)$start->diff($end)->format('%r%a') > 366 * 6) {
                throw new UnexpectedValueException('Report period is invalid or too long.');
            }
        }
        $definitions = $this->rules['definitions'];
        $expected = [
            'sales_amount' => ['source_column' => 'xnetamt', 'aggregation' => 'SUM'],
            'invoice_status' => ['source_column' => 'xstatusord'],
            'returns' => ['source_column' => 'xtype'],
            'working_date' => ['source_column' => 'xworkingdate'],
            'aggregation' => ['grain' => 'one_header_row_per_invoice'],
        ];
        foreach ($expected as $name => $fields) {
            if (($definitions[$name]['source_table'] ?? 'opdoheader') !== 'opdoheader') {
                throw new RuntimeException('Unsupported business rule table.');
            }
            foreach ($fields as $key => $value) {
                if (($definitions[$name][$key] ?? null) !== $value) {
                    throw new RuntimeException('Unsupported business rule definition.');
                }
            }
        }
        if (($definitions['aggregation']['unique_key'] ?? null) !== ['zid', 'xshopno', 'xdornum']) {
            throw new RuntimeException('Invoice grain has not been approved for this compiler.');
        }
        $statuses = $this->approvedValues($definitions['invoice_status']['included_values'] ?? null);
        $unclassifiedTypes = ($definitions['returns']['mode'] ?? null) === 'unclassified_confirmed_documents';
        $saleTypes = $unclassifiedTypes ? [] : $this->approvedValues($definitions['returns']['sale_type_values'] ?? null);
        if (!is_string($definitions['working_date']['timezone'] ?? null) || $definitions['working_date']['timezone'] === '') {
            throw new RuntimeException('Reporting timezone is not approved.');
        }
        $schema = $this->identifier($this->catalog['tables']['opdoheader']['schema_name']);
        $groupBy = $plan['group_by'];
        $date = '[h].[xworkingdate]';
        $dimensionSql = match ($groupBy) {
            'year' => "DATEPART(year, $date) AS [year]",
            'month' => "DATEPART(year, $date) AS [year], DATEPART(month, $date) AS [month]",
            'day' => "CAST($date AS date) AS [day]",
            'shop' => '[h].[xshopno] AS [shop]',
            'none' => '',
            default => throw new UnexpectedValueException('Unsupported grouping.'),
        };
        $groupSql = match ($groupBy) {
            'year' => "DATEPART(year, $date)",
            'month' => "DATEPART(year, $date), DATEPART(month, $date)",
            'day' => "CAST($date AS date)",
            'shop' => '[h].[xshopno]',
            default => '',
        };
        $shops = implode(', ', array_fill(0, count($scope->shopnos), '?'));
        $statusMarkers = implode(', ', array_fill(0, count($statuses), '?'));
        $typeMarkers = implode(', ', array_fill(0, count($saleTypes), '?'));
        $sql = 'SELECT ' . ($dimensionSql !== '' ? $dimensionSql . ', ' : '')
            . 'SUM([h].[xnetamt]) AS [sales_amount] '
            . "FROM [$schema].[opdoheader] AS [h] "
            . 'WHERE ' . ($allHistory ? '' : '[h].[xworkingdate] >= ? AND [h].[xworkingdate] < ? AND ')
            . "[h].[zid] = ? AND [h].[xshopno] IN ($shops) "
            . "AND [h].[xstatusord] IN ($statusMarkers)"
            . ($unclassifiedTypes ? '' : " AND [h].[xtype] IN ($typeMarkers)");
        if ($groupSql !== '') {
            $sql .= " GROUP BY $groupSql ORDER BY $groupSql";
        }
        $bindings = $allHistory ? [] : [
            ['name' => 'date_start', 'type' => 'date', 'source' => 'validated_plan', 'value' => $plan['date_start']],
            ['name' => 'date_end_exclusive', 'type' => 'date', 'source' => 'validated_plan', 'value' => $plan['date_end_exclusive']],
        ];
        $bindings[] = ['name' => 'tenant_zid', 'type' => 'int', 'source' => 'authenticated_session', 'value' => $scope->zid];
        foreach ($scope->shopnos as $i => $shop) {
            $bindings[] = ['name' => "shop_$i", 'type' => 'string', 'source' => 'authenticated_session', 'value' => $shop];
        }
        foreach ($statuses as $i => $value) {
            $bindings[] = ['name' => "included_status_$i", 'type' => 'string', 'source' => 'verified_business_rule', 'value' => $value];
        }
        foreach ($saleTypes as $i => $value) {
            $bindings[] = ['name' => "sale_type_$i", 'type' => 'string', 'source' => 'verified_business_rule', 'value' => $value];
        }
        return ['sql' => $sql, 'bind_parameters' => $bindings, 'selected_metrics' => ['sales_amount'],
            'date_scope' => ['start' => $plan['date_start'], 'end_exclusive' => $plan['date_end_exclusive'],
                'all_authorized_history' => $allHistory,
                'date_column' => 'opdoheader.xworkingdate', 'timezone' => $definitions['working_date']['timezone']],
            'chart_recommendation' => match ($groupBy) {
                'year', 'month', 'day' => 'line', 'shop' => 'bar', default => 'kpi',
            }];
    }

    private function approvedValues(mixed $values): array
    {
        if (!is_array($values) || !$values || count($values) > 20) {
            throw new RuntimeException('Approved business rule values are missing.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 64) {
                throw new RuntimeException('Approved business rule values are invalid.');
            }
        }
        return array_values(array_unique($values));
    }

    private function identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier)) {
            throw new RuntimeException('Invalid verified MSSQL identifier.');
        }
        return $identifier;
    }
}
