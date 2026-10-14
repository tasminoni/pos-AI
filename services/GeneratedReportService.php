<?php
declare(strict_types=1);
require_once __DIR__ . '/GeminiService.php';
require_once __DIR__ . '/QueryApiClient.php';

final class GeneratedReportService
{
    public function __construct(private GeminiService $ai, private QueryApiClient $query, private array $schema, private array $limits = []) {}

    public function handle(string $question, ?array $prior, ReportScope $scope, bool $nextPage = false, ?DateTimeImmutable $clock = null, array $filters = []): array
    {
        $started = microtime(true);
        $now = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->setTimezone(new DateTimeZone('Asia/Dhaka'));
        $base = ['status'=>'error','assistant_text'=>'','error'=>null,'kpis'=>[],
            'table'=>['columns'=>[],'rows'=>[],'row_count'=>0,'export_ready'=>false],
            'chart'=>['type'=>'none','x'=>null,'y'=>null,'data'=>[]], 'filters'=>null,'time_range'=>null,
            'pagination'=>['page_size'=>100,'offset'=>0,'truncated'=>false,'next_offset'=>null],
            'metadata'=>['report'=>'generated_sql','group_by'=>null], 'insights'=>[]];
        try {
            $offset=0;
            if ($nextPage) {
                if (!is_int($prior['next_offset']??null) || !is_array($prior['plan']??null)) { throw new InvalidArgumentException('There is no next page for this report.'); }
                $offset=$prior['next_offset']; $filters=$prior['filters']??[];
                $question=$prior['question']??'';
            }
            if (!$nextPage && (strlen($question) < 2 || strlen($question) > 1200 || preg_match('/password|passwd|card\s*(?:no|number)|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $question))) { throw new InvalidArgumentException('Ask a reporting question without personal identifiers.'); }
            $selectedShop = $filters['shopno'] ?? null;
            if ($selectedShop !== null) { $scope = $scope->forShop($selectedShop); }
            $priorPrompt=$prior===null?null:array_intersect_key($prior,array_flip(['sql','question','title','date_start','date_end_exclusive','columns','presentation']));
            $key = hash('sha256', json_encode([$this->ai->sqlGenerationVersion(),$question,$priorPrompt,$filters,$now->format('Y-m-d'),$this->schema,
                $scope->zid,$scope->shopnos], JSON_THROW_ON_ERROR));
            $cached = $_SESSION['_sql_generation_cache'][$key] ?? null;
            $cacheHit = $nextPage || (is_array($cached) && ($cached['expires'] ?? 0) > time());
            $plan = $nextPage ? $prior['plan'] : ($cacheHit ? $cached['plan'] : $this->ai->generateSql($question,$this->schema,$prior,$now->format('Y-m-d'),$filters));
            $generationCalls=$cacheHit?0:1;
            $sqlRepaired=false;
            if ($plan['status'] === 'clarification') {
                $base['status']='clarification'; $base['assistant_text']=$plan['clarification_question']; return ['response'=>$base,'context'=>$prior];
            }
            $validator=new GeneratedSqlValidator($this->schema, (int)($this->limits['max_rows']??100));
            try { $validator->compile($plan['sql'],$scope,$filters,$offset); }
            catch (QueryValidationException $error) {
                if ($nextPage) { throw $error; }
                if (microtime(true)-$started>=45) {
                    throw new GeminiPlanningException('AI_TIMEOUT','The AI could not produce a valid query within the time budget. Please retry or clarify the question.');
                }
                $generationCalls++;
                $sqlRepaired=true;
                $plan=$this->ai->generateSql($question,$this->schema,$prior,$now->format('Y-m-d'),$filters,
                    ['sql'=>$plan['sql'],'reason'=>$error->reason]);
                if ($plan['status']==='clarification') {
                    $base['status']='clarification'; $base['assistant_text']=$plan['clarification_question'];
                    return ['response'=>$base,'context'=>$prior];
                }
                $validator->compile($plan['sql'],$scope,$filters,$offset);
            }
            $result = $this->query->runSql($plan['sql'],$filters,$offset);
            $safeSqlReasons=['aggregate_requires_numeric_expression','selected_column_missing_from_group_by',
                'unknown_column','unknown_table','sql_syntax_error','incompatible_expression_types'];
            if(!$nextPage && $generationCalls<2 && microtime(true)-$started<45
                && ($result['error']['code']??null)==='QUERY_FAILED'
                && in_array($result['error']['reason']??null,$safeSqlReasons,true)){
                $generationCalls++;
                $sqlRepaired=true;
                $plan=$this->ai->generateSql($question,$this->schema,$prior,$now->format('Y-m-d'),$filters,
                    ['sql'=>$plan['sql'],'reason'=>$result['error']['reason']]);
                if($plan['status']==='clarification'){
                    $base['status']='clarification';$base['assistant_text']=$plan['clarification_question'];
                    return ['response'=>$base,'context'=>$prior];
                }
                $validator->compile($plan['sql'],$scope,$filters,$offset);
                $result=$this->query->runSql($plan['sql'],$filters,$offset);
            }
            if ($result['status'] !== 'ok') {
                $base['assistant_text']=$result['error']['message']; $base['error']=$result['error'];
                return ['response'=>$base,'context'=>$prior];
            }
            if (($result['scope']['zid']??null)!==$scope->zid || ($result['scope']['shopnos']??null)!==$scope->shopnos
                || $result['row_count']!==count($result['rows']) || count($result['rows'])>500) {
                throw new RuntimeException('Internal query scope or result count did not match.');
            }
            // SQL literals supply display bounds only; the API executes and validates the SQL itself.
            preg_match_all("/'(\d{4}-\d{2}-\d{2})'/",$plan['sql'],$matches);
            $dates=array_values(array_unique($matches[1])); sort($dates);
            $measures=array_values(array_filter($result['columns'],static fn($c)=>in_array($c['type'],['decimal','integer'],true)));
            if (count($result['rows'])>1 && $measures && count(array_filter($measures,
                static fn($column)=>count(array_filter(array_column($result['rows'],$column['name']),static fn($v)=>$v!==null))>0))===0) {
                $base['status']='clarification';
                $base['assistant_text']='Matching records exist, but the requested measure has no recorded values. Please choose another stored measure.';
                return ['response'=>$base,'context'=>$prior];
            }
            $empty = !$result['rows'] || (count($result['rows'])===1 && count(array_filter($result['rows'][0],static fn($v)=>$v!==null))===0);
            $base['status']=$empty?'no_data':'ok';
            $base['assistant_text']=$empty?'No matching records were found.':'Here are the database results. Invoice net amounts are stored values; returns are not calculated separately.';
            if ($result['truncated']) { $base['assistant_text'].=($result['next_offset']!==null?' Showing a page of '.count($result['rows']).' rows. Load the next data page for more.':' Showing the first '.count($result['rows']).' rows; narrow your question to see more detail.'); }
            $base['table']=['columns'=>$result['columns'],'rows'=>$empty?[]:$result['rows'],'row_count'=>$empty?0:$result['row_count'],'export_ready'=>true];
            $base['filters']=['tenant_zid'=>$scope->zid,'shopnos'=>$scope->shopnos,'selected_shopno'=>$selectedShop];
            $base['time_range']=['start'=>$dates[0]??null,'end_exclusive'=>count($dates)>1?end($dates):null,
                'timezone'=>'Asia/Dhaka','all_authorized_history'=>!$dates];
            $base['pagination']['truncated']=$result['truncated'];
            $base['pagination']['page_size']=$result['page_size']??100;
            $base['pagination']['offset']=$offset;
            $base['pagination']['next_offset']=$result['next_offset'];
            $base['metadata']+=['title'=>$plan['title'],'query_request_id'=>$result['request_id'],'numeric_source'=>'validated_ai_sql',
                'schema_cache_used'=>!empty($plan['_schema_cache_used']),
                'model_calls'=>$generationCalls,'query_cache_hit'=>$cacheHit&&$generationCalls===0,'sql_repaired'=>$sqlRepaired,
                'duration_ms'=>(int)round((microtime(true)-$started)*1000)];
            $numerics=array_values(array_filter($result['columns'],static fn($c)=>in_array($c['type'],['decimal','integer'],true)));
            if (!$empty && count($result['columns'])===2 && count($result['rows'])>1 && $numerics) {
                [$dimension,$measure]=$result['columns'];
                if (in_array($measure['type'],['decimal','integer'],true)) {
                    $base['chart']=['type'=>($dimension['type']==='date'||preg_match('/year|month|day|period/i',$dimension['name']))?'line':'bar',
                        'x'=>$dimension['name'],'y'=>$measure['name'],'data'=>$result['rows'],'partial_page'=>$result['truncated']];
                }
            }
            if (!$empty && count($result['rows'])===1 && count($result['columns'])===1 && $numerics) {
                $column=$numerics[0]; $base['kpis'][]=['id'=>$column['name'],'label'=>$plan['title'],'value'=>$result['rows'][0][$column['name']],
                    'value_type'=>$column['type'],'source'=>'validated_ai_sql'];
            }
            $context=['report'=>'generated_sql','sql'=>$plan['sql'],'question'=>$question,'title'=>$plan['title'],
                'columns'=>$result['columns'],'presentation'=>count($result['columns'])===1?'summary':'list',
                'plan'=>$plan,'filters'=>$filters,
                'cache_key'=>$key,'cache_plan'=>$plan,
                'date_start'=>$dates[0]??null,'date_end_exclusive'=>count($dates)>1?end($dates):null,'next_offset'=>$result['next_offset']];
            if ($nextPage) { unset($context['cache_key'],$context['cache_plan']); }
            return ['response'=>$base,'context'=>$context];
        } catch (GeminiPlanningException $error) { $base['assistant_text']=$error->getMessage(); $base['error']=['code'=>$error->publicCode]; }
        catch (QueryValidationException) { $base['assistant_text']='The AI query used unsupported SQL or restricted fields. Rephrase the question using reporting data.'; $base['error']=['code'=>'PLAN_REJECTED']; }
        catch (InvalidArgumentException $error) { $base['status']='clarification'; $base['assistant_text']=$error->getMessage(); }
        catch (Throwable $error) { error_log('Generated reporting failed: '.get_class($error)); $base['assistant_text']='The report could not be generated. Please retry.'; $base['error']=['code'=>'REPORTING_UNAVAILABLE']; }
        return ['response'=>$base,'context'=>$prior];
    }
}
