<?php
declare(strict_types=1);

require_once __DIR__ . '/GeminiService.php';
require_once __DIR__ . '/QueryApiClient.php';
require_once __DIR__ . '/SqlValidator.php';

/** Coordinates one model planning call and one authenticated internal query. */
final class ChatReportService
{
    public function __construct(
        private GeminiService $gemini,
        private ReportQueryClient $query,
        private SchemaService $schema,
        private array $rules,
        private array $limits = [],
        private bool $insightsEnabled = false
    ) {}

    /** @return array{response:array,context:?array} */
    public function handle(string $question, ?array $prior, ReportScope $scope,
        bool $nextPage = false, ?DateTimeImmutable $clock = null, array $filters = []): array
    {
        $now = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))
            ->setTimezone(new DateTimeZone('Asia/Dhaka'));
        $planningCalls = 0;
        if ($nextPage) {
            if (!is_array($prior['plan'] ?? null) || !is_int($prior['next_offset'] ?? null)) {
                return $this->reply('clarification', 'There is no next page for this report.', null, null);
            }
            $plan = $prior['plan'];
            $plan['offset'] = $prior['next_offset'];
            $language = $prior['language'] ?? 'en';
        } else {
            $question = trim($question);
            $shopFilter = array_key_exists('shopno', $filters)
                ? $filters['shopno'] : ($prior['plan']['shop_filter'] ?? null);
            if ($shopFilter !== null && (!is_string($shopFilter)
                || !in_array($shopFilter, $scope->shopnos, true))) {
                return $this->reply('clarification', 'Choose a shop from your authorized list.', null, $prior);
            }
            $dateFilter = null;
            if (array_key_exists('date_start', $filters) || array_key_exists('date_end_inclusive', $filters)) {
                if (!is_string($filters['date_start'] ?? null)
                    || !is_string($filters['date_end_inclusive'] ?? null)) {
                    return $this->reply('clarification', 'Choose both a start and end date.', null, $prior);
                }
                try {
                    $startFilter = $this->strictDate($filters['date_start']);
                    $endFilter = $this->strictDate($filters['date_end_inclusive']);
                    if ($endFilter < $startFilter) { throw new UnexpectedValueException('Invalid date range.'); }
                    $dateFilter = ['start' => $startFilter->format('Y-m-d'),
                        'end_exclusive' => $endFilter->modify('+1 day')->format('Y-m-d')];
                } catch (Throwable) {
                    return $this->reply('clarification', 'Choose a valid start and end date.', null, $prior);
                }
            }
            if (strlen($question) < 2 || strlen($question) > 1200 || preg_match('//u', $question) !== 1) {
                return $this->reply('clarification', 'Please enter a shorter reporting question.', null, null);
            }
            if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|(?:\+?88)?01[3-9][0-9]{8}|(?:[0-9][ -]?){12,19}[0-9]|password|passwd|xoldpass|xpassword|card\s*(?:no|number)|phone\s*(?:no|number)/iu', $question)) {
                return $this->reply('clarification', 'Remove personal or sensitive identifiers and ask again.', null, null);
            }
            if (preg_match('/\b(profit|margin|stock|inventory|customer|employee|supplier|vendor|cashier|salary)\b|লাভ|মজুদ|গ্রাহক|কর্মচারী/iu', $question)) {
                return $this->reply('clarification',
                    'That metric or dimension has no verified reporting definition yet.', null, null);
            }
            foreach ($scope->shopnos as $shop) {
                $namedShop = '/(?:shop|store|outlet|দোকান)\s*(?:no\.?|number|#)?\s*[:#-]?\s*'
                    . preg_quote($shop, '/') . '(?![\pL\pN])/iu';
                if (preg_match($namedShop, $question) === 1 && $shopFilter !== $shop) {
                    return $this->reply('clarification',
                        'Individual-shop filtering is not available yet. Ask for a shop-wise report across your authorized shops.',
                        null, null);
                }
            }
            $available = $this->availableReports($scope);
            if (!$available) {
                return $this->reply('error', 'Reporting definitions or MSSQL metadata still need verification.',
                    'REPORTING_NOT_READY', null);
            }
            try {
                $planningCalls = 1;
                $intent = $this->gemini->planChat($question, $prior, $available, $now->format('Y-m-d'),
                    $dateFilter, $shopFilter !== null);
            } catch (InvalidArgumentException) {
                return $this->reply('clarification', 'Please ask about an available sales or invoice report.', null, null);
            } catch (GeminiPlanningException $error) {
                return $this->reply('error', $error->getMessage(), $error->publicCode, null);
            } catch (Throwable) {
                return $this->reply('error', 'I could not interpret the request right now. Please retry.',
                    'PLANNING_UNAVAILABLE', null);
            }
            if ($intent['status'] === 'clarification') {
                return $this->reply('clarification', $this->safeText($intent['clarification_question']), null, null);
            }
            $report = $intent['report'] === 'previous' ? ($prior['report'] ?? null) : $intent['report'];
            $group = $intent['group_by'] === 'previous' ? ($prior['group_by'] ?? null) : $intent['group_by'];
            if (!is_string($report) || !in_array($report, $available, true) || !is_string($group)) {
                return $this->reply('clarification', 'Which verified report and grouping should I use?', null, null);
            }
            try {
                [$start, $end] = $dateFilter === null
                    ? $this->resolveDates($intent, $prior, $now)
                    : [$dateFilter['start'], $dateFilter['end_exclusive']];
            } catch (Throwable) {
                return $this->reply('clarification', 'Which date range should I use?', null, null);
            }
            $pageSize = $report === 'sales_amount' && $group === 'none' ? 1 : min(50,
                max(1, (int)($this->limits['max_rows'] ?? 100)));
            $plan = ['report' => $report, 'group_by' => $group,
                'date_start' => $start, 'date_end_exclusive' => $end,
                'page_size' => $pageSize, 'offset' => 0, 'shop_filter' => $shopFilter];
            $language = $intent['language'];
        }
        try {
            $compiled = (new SqlValidator($this->schema->catalog(), $this->rules, $this->limits))
                ->validateAndCompile($plan, $scope);
        } catch (QueryValidationException) {
            return $this->reply('clarification', 'This report or date range is not approved. Please narrow or clarify the request.',
                null, $prior);
        }
        try {
            // The API repeats validation and owns all SQL construction and execution.
            $result = $this->query->run($plan);
        } catch (Throwable) {
            return $this->reply('error', 'The report service is unavailable. Please retry.',
                'QUERY_UNAVAILABLE', $prior);
        }
        if (($result['status'] ?? null) !== 'ok') {
            $receivedCode = $result['error']['code'] ?? null;
            $code = in_array($receivedCode, ['PLAN_REJECTED', 'QUERY_TIMEOUT', 'RESULT_TOO_LARGE',
                'QUERY_FAILED'], true) ? $receivedCode : 'QUERY_FAILED';
            $message = match ($code) {
                'PLAN_REJECTED' => 'This report is not approved for execution.',
                'QUERY_TIMEOUT' => 'The report timed out. Try a shorter period or coarser grouping.',
                'RESULT_TOO_LARGE' => 'A report row is too large to return safely.',
                default => 'The report could not be completed. Please retry.',
            };
            return $this->reply('error', $message, $code, $prior);
        }
        if (($result['columns'] ?? null) !== $compiled['columns']
            || ($result['scope'] ?? null) !== $compiled['scope']
            || ($result['date_scope'] ?? null) !== $compiled['date_scope']
            || !is_array($result['rows'] ?? null)
            || ($result['row_count'] ?? null) !== count($result['rows'])
            || count($result['rows']) > $plan['page_size']
            || !is_bool($result['truncated'] ?? null)
            || (($result['truncated'] ?? false) === false && ($result['next_offset'] ?? null) !== null)
            || (is_int($result['next_offset'] ?? null)
                && $result['next_offset'] !== $plan['offset'] + $result['row_count'])) {
            return $this->reply('error', 'The report result could not be verified.', 'INVALID_RESULT', $prior);
        }
        $columnNames = array_column($compiled['columns'], 'name');
        foreach ($result['rows'] as $row) {
            if (!is_array($row) || array_keys($row) !== $columnNames) {
                return $this->reply('error', 'The report result could not be verified.', 'INVALID_RESULT', $prior);
            }
            if (($plan['group_by'] === 'month' && (!is_int($row['year']) || $row['year'] < 1
                    || !is_int($row['month']) || $row['month'] < 1 || $row['month'] > 12))
                || ($plan['group_by'] === 'year' && (!is_int($row['year']) || $row['year'] < 1))) {
                return $this->reply('error', 'The report period could not be verified.', 'INVALID_RESULT', $prior);
            }
        }
        $rows = $result['rows'];
        if (in_array($plan['report'], ['sales_amount', 'product_sales_amount'], true)) {
            foreach ($rows as $row) {
                if (($row['sales_amount'] ?? null) !== null && !$this->decimal($row['sales_amount'])) {
                    return $this->reply('error', 'The database returned an invalid amount.', 'INVALID_RESULT', $prior);
                }
            }
        }
        $isEmpty = $rows === [] || ($plan['report'] === 'sales_amount' && $plan['group_by'] === 'none'
            && ($rows[0]['sales_amount'] ?? null) === null);
        $kpis = [];
        if (!$isEmpty && $plan['report'] === 'sales_amount' && $plan['group_by'] === 'none') {
            $amount = $rows[0]['sales_amount'] ?? null;
            if (!$this->decimal($amount)) {
                return $this->reply('error', 'The database returned an invalid amount.', 'INVALID_RESULT', $prior);
            }
            $kpis[] = ['id' => 'sales_amount', 'label' => 'Sales amount', 'value' => (string)$amount,
                'value_type' => 'decimal', 'source' => 'verified_sql_aggregate'];
        }
        $chartType = match ($plan['group_by']) {
            'year', 'month', 'day' => 'line', 'shop', 'product' => 'bar',
            default => 'none',
        };
        if ($isEmpty || $plan['report'] === 'invoice_list' || $plan['report'] === 'invoice_lines') {
            $chartType = 'none';
        }
        $chart = ['type' => $chartType, 'x' => null, 'y' => null, 'data' => []];
        if ($chartType !== 'none') {
            $x = match ($plan['group_by']) {
                'year' => 'year', 'month' => 'period', 'day' => 'day', 'shop' => 'shop', default => 'product',
            };
            $chartRows = $plan['group_by'] === 'month' ? array_map(static function (array $row): array {
                return ['period' => sprintf('%04d-%02d', (int)$row['year'], (int)$row['month']),
                    'sales_amount' => $row['sales_amount']];
            }, $rows) : $rows;
            $chart = ['type' => $chartType, 'x' => $x, 'y' => 'sales_amount', 'data' => $chartRows,
                'partial_page' => (bool)$result['truncated'] || $plan['offset'] > 0];
        }
        $text = $isEmpty ? match ($language) {
            'bn' => 'এই সময়সীমায় কোনো তথ্য পাওয়া যায়নি।',
            'banglish' => 'Ei shomoy kono data paowa jayni.',
            default => 'No data was found for this period and scope.',
        } : match ($language) {
            'bn' => 'যাচাইকৃত রিপোর্টের ফলাফল নিচে দেওয়া হলো।',
            'banglish' => 'Verified report er folafol niche deya holo.',
            default => 'Here are the verified report results.',
        };
        $context = ['report' => $plan['report'], 'group_by' => $plan['group_by'],
            'date_start' => $plan['date_start'], 'date_end_exclusive' => $plan['date_end_exclusive'],
            'language' => $language, 'plan' => $plan,
            'next_offset' => is_int($result['next_offset'] ?? null) ? $result['next_offset'] : null];
        if ($plan['report'] === 'sales_amount' && ($this->rules['definitions']['sales_amount']['status'] ?? null) === 'configured') {
            $text = ($isEmpty ? $text . ' ' : '') . 'This report sums stored net amounts for Confirmed invoices by working date. Dismissed invoices are excluded; returns are not calculated separately.';
        }
        $insights = [];
        $modelCalls = $planningCalls;
        if ($this->insightsEnabled && !$nextPage && !$isEmpty && !$result['truncated']
            && $plan['offset'] === 0 && $plan['report'] === 'sales_amount'
            && in_array($plan['group_by'], ['year', 'month', 'day'], true) && count($rows) >= 2
            && $this->decimal($rows[0]['sales_amount'] ?? null)
            && $this->decimal($rows[array_key_last($rows)]['sales_amount'] ?? null)) {
            $comparison = $this->compareAmounts((string)$rows[array_key_last($rows)]['sales_amount'],
                (string)$rows[0]['sales_amount']);
            $direction = $comparison > 0 ? 'higher' : ($comparison < 0 ? 'lower' : 'unchanged');
            $modelCalls++;
            $written = $this->gemini->writeInsight($direction, $plan['group_by'], $language);
            if ($written !== null) {
                $insights = [$written['insight'], $written['recommendation']];
            }
        }
        $response = [
            'status' => $isEmpty ? 'no_data' : 'ok', 'assistant_text' => $text, 'error' => null,
            'kpis' => $kpis,
            'table' => ['columns' => $result['columns'], 'rows' => $isEmpty ? [] : $rows,
                'row_count' => $isEmpty ? 0 : $result['row_count'], 'export_ready' => true],
            'chart' => $chart,
            'insights' => $insights,
            'filters' => ['tenant_zid' => $compiled['scope']['zid'],
                'shopnos' => $compiled['scope']['shopnos'],
                'selected_shopno' => $plan['shop_filter'] ?? null],
            'time_range' => ['start' => $plan['date_start'], 'end_exclusive' => $plan['date_end_exclusive'],
                'timezone' => 'Asia/Dhaka', 'all_authorized_history' => $plan['date_start'] === null],
            'pagination' => ['page_size' => $plan['page_size'], 'offset' => $plan['offset'],
                'truncated' => (bool)$result['truncated'], 'next_offset' => $context['next_offset']],
            'metadata' => ['report' => $plan['report'], 'group_by' => $plan['group_by'],
                'query_request_id' => $result['request_id'] ?? null, 'numeric_source' => 'verified_sql',
                'model_calls' => $modelCalls,
                'insights_source' => $insights ? 'gemini_from_verified_trend_label' : null],
        ];
        return ['response' => $response, 'context' => $context];
    }

    private function availableReports(ReportScope $scope): array
    {
        $validator = new SqlValidator($this->schema->catalog(), $this->rules, $this->limits);
        $available = [];
        foreach (['sales_amount' => 'year', 'product_sales_amount' => 'product',
            'invoice_list' => 'none', 'invoice_lines' => 'none'] as $report => $group) {
            try {
                $validator->validateAndCompile(['report' => $report, 'group_by' => $group,
                    'date_start' => '2025-01-01', 'date_end_exclusive' => '2025-01-02',
                    'page_size' => 1, 'offset' => 0], $scope);
                $available[] = $report;
            } catch (QueryValidationException) {
                // Missing verification keeps this report out of the model prompt.
            }
        }
        return $available;
    }


    private function resolveDates(array $intent, ?array $prior, DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0);
        $year = (int)$today->format('Y');
        $mode = $intent['date_mode'];
        $pair = match ($mode) {
            'today' => [$today, $today->modify('+1 day')],
            'yesterday' => [$today->modify('-1 day'), $today],
            'this_week' => [$today->modify('monday this week'), $today->modify('+1 day')],
            'last_week' => [$today->modify('monday last week'), $today->modify('monday this week')],
            'this_month' => [$today->modify('first day of this month'), $today->modify('+1 day')],
            'last_month' => [$today->modify('first day of last month'), $today->modify('first day of this month')],
            'this_year' => [new DateTimeImmutable("$year-01-01", $today->getTimezone()),
                $today->modify('+1 day')],
            'last_year' => [new DateTimeImmutable(($year - 1) . '-01-01', $today->getTimezone()),
                new DateTimeImmutable("$year-01-01", $today->getTimezone())],
            'last_n_years' => $this->years($intent['year_count'], $year, $today),
            'all_history' => [null, null],
            'previous' => $this->previousDates($prior),
            'explicit' => [$this->strictDate($intent['date_start']), $this->strictDate($intent['date_end_exclusive'])],
            default => throw new UnexpectedValueException('Unsupported date mode.'),
        };
        if ($pair[0] === null) { return [null, null]; }
        if ($pair[0] >= $pair[1] || (int)$pair[0]->diff($pair[1])->format('%r%a') > 366 * 6) {
            throw new UnexpectedValueException('Date range is invalid.');
        }
        return [$pair[0]->format('Y-m-d'), $pair[1]->format('Y-m-d')];
    }

    private function years(string $count, int $year, DateTimeImmutable $today): array
    {
        $number = (int)$count;
        if ($number < 1 || $number > 6) { throw new UnexpectedValueException('Year count is invalid.'); }
        return [new DateTimeImmutable(($year - $number + 1) . '-01-01', $today->getTimezone()),
            $today->modify('+1 day')];
    }

    private function previousDates(?array $prior): array
    {
        if ($prior === null || !array_key_exists('date_start', $prior)
            || !array_key_exists('date_end_exclusive', $prior)) {
            throw new UnexpectedValueException('No prior date range.');
        }
        if ($prior['date_start'] === null && $prior['date_end_exclusive'] === null) { return [null, null]; }
        return [$this->strictDate($prior['date_start']), $this->strictDate($prior['date_end_exclusive'])];
    }

    private function strictDate(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) { throw new UnexpectedValueException('Date is invalid.'); }
        try { $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Dhaka')); }
        catch (ValueError) { throw new UnexpectedValueException('Date is invalid.'); }
        if (!$date || $date->format('Y-m-d') !== $value) { throw new UnexpectedValueException('Date is invalid.'); }
        return $date;
    }

    private function decimal(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value))
            && preg_match('/^-?[0-9]{1,76}(?:\.[0-9]{1,38})?$/D', (string)$value) === 1;
    }

    private function compareAmounts(string $left, string $right): int
    {
        $negativeLeft = str_starts_with($left, '-');
        $negativeRight = str_starts_with($right, '-');
        $left = ltrim($left, '-');
        $right = ltrim($right, '-');
        [$leftInt, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInt, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $leftInt = ltrim($leftInt, '0') ?: '0';
        $rightInt = ltrim($rightInt, '0') ?: '0';
        $leftZero = $leftInt === '0' && trim($leftFraction, '0') === '';
        $rightZero = $rightInt === '0' && trim($rightFraction, '0') === '';
        if ($leftZero && $rightZero) { return 0; }
        if ($negativeLeft !== $negativeRight) { return $negativeLeft ? -1 : 1; }
        $magnitude = strlen($leftInt) <=> strlen($rightInt);
        if ($magnitude === 0) { $magnitude = strcmp($leftInt, $rightInt) <=> 0; }
        if ($magnitude === 0) {
            $length = max(strlen($leftFraction), strlen($rightFraction));
            $magnitude = strcmp(str_pad($leftFraction, $length, '0'),
                str_pad($rightFraction, $length, '0')) <=> 0;
        }
        return $negativeLeft ? -$magnitude : $magnitude;
    }

    private function safeText(string $text): string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', ' ', $text) ?? '');
        preg_match_all('/./us', $clean, $characters);
        return implode('', array_slice($characters[0], 0, 300));
    }

    private function reply(string $status, string $text, ?string $code, ?array $context): array
    {
        return ['response' => ['status' => $status, 'assistant_text' => $text,
            'error' => $code === null ? null : ['code' => $code, 'message' => $text],
            'kpis' => [], 'table' => ['columns' => [], 'rows' => [], 'row_count' => 0, 'export_ready' => false],
            'chart' => ['type' => 'none', 'x' => null, 'y' => null, 'data' => []],
            'filters' => null, 'time_range' => null,
            'pagination' => ['page_size' => 0, 'offset' => 0, 'truncated' => false, 'next_offset' => null],
            'metadata' => ['report' => null, 'group_by' => null, 'query_request_id' => null]],
            'context' => $context];
    }
}
