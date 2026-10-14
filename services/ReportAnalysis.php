<?php
declare(strict_types=1);

/** AI chooses presentation; PHP computes statistics using API rows only. */
final class ReportAnalysis
{
    public static function validatePresentation(mixed $input): array
    {
        if(!is_array($input) || array_diff(array_keys($input),['chart_type','x','y','period_grain','analysis','language','column_labels'])){
            throw new InvalidArgumentException('Invalid report presentation.');
        }
        $p=array_replace(['chart_type'=>'auto','x'=>'','y'=>'','period_grain'=>'none','analysis'=>[],'language'=>'en','column_labels'=>[]],$input);
        if(!in_array($p['chart_type'],['auto','table','bar','line','pie','doughnut'],true)
            || !in_array($p['period_grain'],['none','day','month','year'],true)
            || !in_array($p['language'],['en','bn'],true)
            || !is_string($p['x']) || !is_string($p['y']) || strlen($p['x'])>128 || strlen($p['y'])>128
            || !is_array($p['analysis']) || !array_is_list($p['analysis']) || count($p['analysis'])>4){
            throw new InvalidArgumentException('Invalid report presentation.');
        }
        foreach($p['analysis'] as $kind){if(!in_array($kind,['highest_period','lowest_period','trend','period_change'],true)){throw new InvalidArgumentException('Unsupported report analysis.');}}
        if(!is_array($p['column_labels']) || !array_is_list($p['column_labels']) || count($p['column_labels'])>40){throw new InvalidArgumentException('Invalid column labels.');}
        foreach($p['column_labels'] as $label){
            if(!is_array($label)||array_diff(array_keys($label),['name','label'])||!is_string($label['name']??null)||!is_string($label['label']??null)
                ||strlen($label['name'])>128||strlen($label['label'])>160||trim($label['label'])===''){throw new InvalidArgumentException('Invalid column label.');}
        }
        $p['analysis']=array_values(array_unique($p['analysis']));
        return $p;
    }

    public static function build(array $result,array $presentation,DateTimeImmutable $now,array $bounds=[],bool $subset=false): array
    {
        $p=self::validatePresentation($presentation);
        $columns=array_column($result['columns'],'type','name');
        if($p['x']==='' && $p['y']==='' && count($columns)===2){[$p['x'],$p['y']]=array_keys($columns);}
        $out=['chart'=>['type'=>'none','x'=>null,'y'=>null,'data'=>[]],
            'insights'=>[],'analysis'=>['source'=>'api_rows','partial_dataset'=>$subset,'period_changes'=>[]],'presentation'=>$p];
        $bn=$p['language']==='bn';
        if($p['x']==='' || $p['y']===''){return $out;}
        if($p['x']===$p['y'] || !isset($columns[$p['x']],$columns[$p['y']]) || !in_array($columns[$p['y']],['integer','decimal'],true)){
            $out['insights'][]=$bn?'চার্টের জন্য সঠিক লেবেল ও সংখ্যার কলাম পাওয়া যায়নি। নিচের টেবিল দেখুন।':'The requested chart axes do not match a label and numeric result column. See the table.';
            return $out;
        }
        $rows=$result['rows'];
        if(!$rows){return $out;}
        $type=$p['chart_type'];
        if($type==='auto'){$type=$p['period_grain']!=='none'||$columns[$p['x']]==='date'||preg_match('/year|month|day|period/i',$p['x'])?'line':'bar';}
        $valid=true;$negative=false;$total=0.0;
        foreach($rows as $row){
            $value=$row[$p['y']]??null;
            if(!is_scalar($value)||!preg_match('/^-?\d+(?:\.\d+)?$/D',(string)$value)||!is_finite((float)$value)||abs((float)$value)>9007199254740991){$valid=false;break;}
            $negative=$negative||(float)$value<0;$total+=(float)$value;
        }
        if(!$valid){$out['insights'][]=$bn?'কিছু মান নেই বা চার্টের সংখ্যাগত সীমার বাইরে। সঠিক মান টেবিলে দেখুন।':'Some values are missing or outside chart numeric limits. Exact values remain in the table.';return $out;}
        if(in_array($type,['pie','doughnut'],true)&&($negative||$total<=0)){
            $type='bar';$out['insights'][]=$bn?'ঋণাত্মক মান বা শূন্য মোট দিয়ে পাই চার্ট করা যায় না; বার চার্ট দেখানো হলো।':'A pie/donut needs nonnegative values and a positive total; showing a bar chart instead.';
        }
        $calendar=[];
        foreach($rows as $row){$period=self::period($row[$p['x']]??null,$p['period_grain'],$now);if($period===null){$calendar=[];break;}$calendar[]=['row'=>$row,'start'=>$period,'end'=>$period->modify(match($p['period_grain']){'month'=>'+1 month','year'=>'+1 year',default=>'+1 day'})];}
        if($calendar){usort($calendar,static fn($a,$b)=>$a['start']<=>$b['start']);$rows=array_column($calendar,'row');}
        if($p['chart_type']!=='table'){$out['chart']=['type'=>$type,'x'=>$p['x'],'y'=>$p['y'],'data'=>$rows,'partial_page'=>$subset];}
        if(!$p['analysis']){return $out;}
        if($subset){$out['insights'][]=$bn?'ফলাফল আংশিক। পুরো সময়ের সর্বোচ্চ বিক্রয় বা ট্রেন্ড নির্ধারণ করা হয়নি।':'This is a partial dataset. Whole-period peak and trend analysis is withheld; narrow the range to return all grouped periods.';return $out;}
        if(!$calendar){$out['insights'][]=$bn?'ট্রেন্ড বিশ্লেষণের জন্য বছরসহ দিন, মাস বা বছরের সমষ্টি প্রয়োজন।':'Period analysis needs one calendar bucket per row, including its year. Category/detail rows are not a time trend.';return $out;}
        $seen=[];$complete=[];$excluded=[];
        foreach($calendar as $item){
            $key=$item['start']->format('Y-m-d');
            if(isset($seen[$key])){$out['insights'][]=$bn?'একই সময়ের একাধিক সারি রয়েছে। এক সারিতে সময়ের মোট দিয়ে আবার চেষ্টা করুন।':'Multiple rows share a period. Return one aggregate per period before analyzing a trend.';return $out;}
            $seen[$key]=true;
            $isComplete=$item['end']<=$now->setTime(0,0);
            if(isset($bounds['start'])){$isComplete=$isComplete&&$item['start']>=new DateTimeImmutable($bounds['start'],$now->getTimezone());}
            if(isset($bounds['end_exclusive'])){$isComplete=$isComplete&&$item['end']<=new DateTimeImmutable($bounds['end_exclusive'],$now->getTimezone());}
            if($isComplete){$complete[]=$item;}else{$excluded[]=$item['row'][$p['x']];}
        }
        if($excluded){$out['insights'][]=$bn?'চলতি বা ফিল্টারে আংশিক সময় চার্টে আছে, কিন্তু তুলনামূলক বিশ্লেষণ থেকে বাদ দেওয়া হয়েছে।':'Current or filter-cut periods remain in the chart but are excluded from comparative analysis.';}
        $out['analysis']['excluded_periods']=$excluded;
        $out['analysis']['complete_period_count']=count($complete);
        if(!$complete){$out['insights'][]=$bn?'তুলনা করার জন্য কোনো পূর্ণ সময়ের তথ্য নেই।':'No complete calendar periods are available for comparison.';return $out;}
        foreach(['highest_period','lowest_period'] as $kind){
            if(!in_array($kind,$p['analysis'],true)){continue;}
            $best=$complete[0]['row'];$ties=[];
            foreach($complete as $item){$row=$item['row'];$cmp=self::compare((string)$row[$p['y']],(string)$best[$p['y']]);if($kind==='lowest_period'){$cmp=-$cmp;}if($cmp>0){$best=$row;$ties=[];}elseif($cmp===0&&$row[$p['x']]!==$best[$p['x']]){$ties[]=$row[$p['x']];}}
            $out['analysis'][$kind]=['period'=>$best[$p['x']],'value'=>$best[$p['y']],'tied_periods'=>$ties];
            $out['insights'][]=$bn?($kind==='highest_period'?'পূর্ণ সময়গুলোর সর্বোচ্চ: ':'পূর্ণ সময়গুলোর সর্বনিম্ন: ').$best[$p['x']].' — '.$best[$p['y']].($ties?' (সমান মানের আরও সময় আছে)':'')
                :($kind==='highest_period'?'Highest complete period: ':'Lowest complete period: ').$best[$p['x']].' — '.$best[$p['y']].($ties?' (other periods are tied)':'');
        }
        if(!array_intersect($p['analysis'],['trend','period_change'])){return $out;}
        if(count($complete)<2){$out['insights'][]=$bn?'ট্রেন্ড তুলনার জন্য অন্তত দুটি পূর্ণ সময় প্রয়োজন।':'At least two complete periods are needed to compare a trend.';return $out;}
        $changes=[];$gaps=false;$up=0;$down=0;
        for($i=1;$i<count($complete);$i++){
            $previous=$complete[$i-1];$current=$complete[$i];$adjacent=$previous['end']==$current['start'];$gaps=$gaps||!$adjacent;
            $cmp=self::compare((string)$current['row'][$p['y']],(string)$previous['row'][$p['y']]);if($cmp>0){$up++;}elseif($cmp<0){$down++;}
            $changes[]=['from'=>$previous['row'][$p['x']],'to'=>$current['row'][$p['x']],
                'percent_change'=>self::percent((float)$previous['row'][$p['y']],(float)$current['row'][$p['y']]),'adjacent_periods'=>$adjacent];
        }
        if(in_array('period_change',$p['analysis'],true)){$out['analysis']['period_changes']=$changes;}
        $first=$complete[0]['row'];$last=$complete[array_key_last($complete)]['row'];
        $sign=self::compare((string)$last[$p['y']],(string)$first[$p['y']]);$percent=self::percent((float)$first[$p['y']],(float)$last[$p['y']]);
        $out['analysis']['trend']=['from'=>$first[$p['x']],'to'=>$last[$p['x']],'first_value'=>$first[$p['y']],'last_value'=>$last[$p['y']],
            'direction'=>$sign>0?'increasing':($sign<0?'decreasing':'unchanged'),'percent_change'=>$percent,'method'=>'first_to_last_complete_period'];
        $latest=$changes[array_key_last($changes)];
        $latestSign=self::compare((string)$last[$p['y']],(string)$complete[count($complete)-2]['row'][$p['y']]);
        $out['analysis']['latest_change']=$latest+['direction'=>$latestSign>0?'increasing':($latestSign<0?'decreasing':'unchanged')];
        if(in_array('trend',$p['analysis'],true)){
            $out['insights'][]=$bn?'প্রথম পূর্ণ সময় থেকে শেষ পূর্ণ সময় পর্যন্ত মান '.($sign>0?'বেড়েছে':($sign<0?'কমেছে':'অপরিবর্তিত')).($percent!==null?' ('.number_format(abs($percent),2).'% পরিবর্তন)':' (শূন্য/ঋণাত্মক ভিত্তিতে শতাংশ প্রযোজ্য নয়)').'।'
                :'From '.$first[$p['x']].' to '.$last[$p['x']].', the value '.($sign>0?'increased':($sign<0?'decreased':'was unchanged')).($percent!==null?' by '.number_format(abs($percent),2).'%':'; percentage change is undefined for a zero/negative baseline').'.';
            if($up&&$down){$out['insights'][]=$bn?'মাঝের সময়গুলোতে ওঠানামা আছে; প্রতি সময়ে একই দিকে পরিবর্তন হয়নি।':'The intervening periods fluctuate; this is not a steady increase or decrease.';}
            $latestPercent=$latest['percent_change'];
            $out['insights'][]=$bn?'সর্বশেষ পূর্ণ সময় '.$latest['to'].'-এ আগের পাওয়া সময়ের তুলনায় মান '.($latestSign>0?'বেড়েছে':($latestSign<0?'কমেছে':'অপরিবর্তিত')).($latestPercent!==null?' ('.number_format(abs($latestPercent),2).'%)':' (শতাংশ প্রযোজ্য নয়)').'।'
                :'Latest complete period '.$latest['to'].': '.($latestSign>0?'increase':($latestSign<0?'decrease':'no change')).($latestPercent!==null?' of '.number_format(abs($latestPercent),2).'%':' (percentage undefined)').' versus the previous recorded period.';
        }
        if($gaps){$out['insights'][]=$bn?'কিছু সময়ের সারি নেই। তুলনা পাওয়া সময়গুলোর মধ্যে; অনুপস্থিত সময়কে শূন্য ধরা হয়নি।':'Some calendar periods are missing. Changes compare recorded periods; missing periods are not treated as zero.';}
        return $out;
    }

    private static function period(mixed $value,string $grain,DateTimeImmutable $now):?DateTimeImmutable
    {
        if(!is_scalar($value)||$grain==='none'){return null;}$text=(string)$value;
        if($grain==='year'&&preg_match('/^\d{4}$/D',$text)){$text.='-01-01';}
        elseif($grain==='month'&&preg_match('/^\d{4}-\d{2}$/D',$text)){$text.='-01';}
        elseif(preg_match('/^\d{4}-\d{2}-\d{2}(?:$|[ T])/D',$text)){$text=substr($text,0,10);}else{return null;}
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$text,$now->getTimezone());
        if(!$date||$date->format('Y-m-d')!==$text||($grain==='month'&&$date->format('d')!=='01')||($grain==='year'&&$date->format('m-d')!=='01-01')){return null;}
        return $date;
    }

    private static function percent(float $from,float $to):?float
    {
        if($from<=0){return null;}$value=($to-$from)/$from*100;
        return is_finite($value)?round($value,2):null;
    }

    public static function friendlyText(string $message,array $presentation):string
    {
        $grain=$presentation['period_grain']??'none';$bn=($presentation['language']??'en')==='bn';
        $period=match($grain){'month'=>'month','year'=>'year','day'=>'day',default=>'period'};
        $bnPeriod=match($grain){'month'=>'মাস','year'=>'বছর','day'=>'দিন',default=>'সময়'};
        $message=str_replace(['Highest complete period:','Lowest complete period:','Latest complete period',
            'পূর্ণ সময়গুলোর সর্বোচ্চ:','পূর্ণ সময়গুলোর সর্বনিম্ন:','সর্বশেষ পূর্ণ সময়'],
            ['Highest '.$period.':','Lowest '.$period.':','Latest '.$period,
            'সর্বোচ্চ মানের '.$bnPeriod.':','সর্বনিম্ন মানের '.$bnPeriod.':','সর্বশেষ '.$bnPeriod],$message);
        $months=$bn?['জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর']
            :['January','February','March','April','May','June','July','August','September','October','November','December'];
        $message=preg_replace_callback('/\b(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}:\d{2})?\b/',static function($match)use($grain,$months){
            $index=(int)$match[2]-1;if($index<0||$index>11){return $match[0];}
            return $grain==='year'?$match[1]:($grain==='month'?$months[$index].' '.$match[1]:(int)$match[3].' '.$months[$index].' '.$match[1]);
        },$message);
        return preg_replace_callback('/(?<![\d,.])(-?\d+)\.(\d+)(?![\d.])/',static fn($match)=>preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$match[1]).'.'.$match[2],$message);
    }

    private static function compare(string $a,string $b):int
    {
        $negativeA=str_starts_with($a,'-');$negativeB=str_starts_with($b,'-');
        [$wholeA,$fractionA]=array_pad(explode('.',ltrim($a,'-'),2),2,'');[$wholeB,$fractionB]=array_pad(explode('.',ltrim($b,'-'),2),2,'');
        $wholeA=ltrim($wholeA,'0')?:'0';$wholeB=ltrim($wholeB,'0')?:'0';$digits=max(strlen($fractionA),strlen($fractionB));
        if($wholeA==='0'&&trim($fractionA,'0')===''){$negativeA=false;}if($wholeB==='0'&&trim($fractionB,'0')===''){$negativeB=false;}
        if($negativeA!==$negativeB){return $negativeA?-1:1;}
        $cmp=strlen($wholeA)<=>strlen($wholeB);$cmp=$cmp?:strcmp($wholeA,$wholeB);$cmp=$cmp?:strcmp(str_pad($fractionA,$digits,'0'),str_pad($fractionB,$digits,'0'));
        return $negativeA?-$cmp:$cmp;
    }
}
