<?php
require_once __DIR__.'/database.php';

function dn_analysis_bins(int $start, int $end): array {
    $span=$end-$start;
    if ($span<=0) throw new RuntimeException('结束时间须晚于开始时间');
    $kind=$span<=86400?'hour':($span<=45*86400?'day':($span<=180*86400?'week':($span<=730*86400?'month':'year')));
    if ($kind==='hour') $cursor=$start-(int)date('i',$start)*60-(int)date('s',$start);
    else {
        $cursor=strtotime(date($kind==='month'?'Y-m-01 00:00:00':($kind==='year'?'Y-01-01 00:00:00':'Y-m-d 00:00:00'),$start));
        if($kind==='week')$cursor=strtotime('-'.((int)date('N',$cursor)-1).' days',$cursor);
    }
    $bins=[];
    while($cursor<$end){
        $next=$kind==='hour'?$cursor+3600:strtotime('+1 '.$kind,$cursor);
        $left=max($start,$cursor);$right=min($end,$next);
        $format=['hour'=>'H:i','day'=>'m-d','week'=>'m-d','month'=>'Y-m','year'=>'Y'];
        $bins[]=['start'=>$left,'end'=>$right,'label'=>date($format[$kind],$cursor),
            'range'=>date('Y-m-d H:i',$left).' 至 '.date('Y-m-d H:i',$right)];
        if(count($bins)>1000)throw new RuntimeException('日期范围过大，请缩小范围');
        $cursor=$next;
    }
    return ['kind'=>$kind,'bins'=>$bins];
}
function dn_analysis_bin_index(array $bins, int $time): ?int {
    $left=0;$right=count($bins)-1;
    while($left<=$right){
        $mid=intdiv($left+$right,2);
        if($time<$bins[$mid]['start'])$right=$mid-1;
        elseif($time>=$bins[$mid]['end'])$left=$mid+1;
        else return $mid;
    }
    return null;
}

function dn_analysis(SQLite3 $db, array $disks, int $start, int $end, bool $summaryOnly = false): array {
    if ($end <= $start) throw new RuntimeException('结束时间须晚于开始时间');
    $period=$summaryOnly?['kind'=>null,'bins'=>[]]:dn_analysis_bins($start,$end); $bins=$period['bins'];
    $series = [];
    foreach ($disks as $id=>$disk) {
        $series[$id] = ['id'=>(string)$id,'label'=>$disk['slot'],'awake'=>0,'sleep'=>0,'wakes'=>0,
            'complete_count'=>0, 'coarse'=>0,
            'periods'=>array_fill(0,count($bins),['awake'=>0,'sleep'=>0,'wakes'=>0]), 'distribution'=>array_fill(0,5,0)];
    }
    if (!$series) return ['start'=>$start,'end'=>$end,'granularity'=>$period['kind'],'bins'=>$bins,'series'=>[],'clock_gaps'=>0];
    $params=['start'=>$start,'end'=>$end]; $ids=[];
    foreach(array_keys($series) as $i=>$id) { $ids[]=':disk'.$i; $params['disk'.$i]=(string)$id; }
    $join=$end-$start<=45*86400
        ? 'observations AS o INDEXED BY observations_time CROSS JOIN disk_samples AS s'
        : 'observations o JOIN disk_samples s';
    $result = dn_db_run($db, 'SELECT o.id,o.sampled_at,o.boot,o.uptime,o.interval_minutes,s.*
        FROM '.$join.' ON s.observation_id=o.id
        WHERE o.sampled_at>=:start AND (o.sampled_at<=:end OR s.interval_start<:end)
        AND s.disk_id IN ('.implode(',',$ids).') ORDER BY o.id,s.disk_id', $params);
    $runs = []; $last = []; $horizon = []; $count = 0; $clock = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (++$count > 500000) throw new RuntimeException('记录较多，请缩短日期范围或选择单块硬盘');
        $id = $row['disk_id'];
        if (!isset($series[$id])) continue;
        $item = &$series[$id];
        $a = $row['interval_start']; $b = $row['sampled_at'];
        $seconds = $row['awake_seconds']+$row['sleep_seconds'];
        $valid = $a !== null && $b>$a && $seconds>0 && abs(($b-$a)-$seconds)<=2;
        if ($valid && isset($last[$id]) && ($last[$id]['boot']!==$row['boot'] || $last[$id]['uptime']>=$row['uptime'] || $last[$id]['sampled_at']>$b)) $valid=false;
        if ($valid && isset($horizon[$id]) && $a<$horizon[$id]) $valid=false;
        $horizon[$id]=max($horizon[$id]??$b,$b);
        if (!$valid) {
            if ($a!==null && $seconds>0) $clock++;
            unset($runs[$id]); $last[$id]=$row; unset($item); continue;
        }
        $kind = $row['sleep_seconds']>0 ? 'sleep' : 'awake';
        $from=max($start,$a); $to=min($end,$b);
        $factor=min(1,$seconds/($b-$a));
        $amount=max(0,$to-$from)*$factor;
        $item[$kind]+=$amount;
        if ($b >= $start && $b < $end) $item['wakes']+=$row['wakeups'];
        if ($summaryOnly) { $last[$id]=$row; unset($item); continue; }
        $index=dn_analysis_bin_index($bins,$from);
        while($index!==null && $index<count($bins) && $bins[$index]['start']<$to){
            $overlap=max(0,min($to,$bins[$index]['end'])-max($from,$bins[$index]['start']));
            $item['periods'][$index][$kind]+=$overlap*$factor;
            $index++;
        }
        if ($b >= $start && $b < $end) {
            $index=dn_analysis_bin_index($bins,$b);
            if($index!==null)$item['periods'][$index]['wakes']+=$row['wakeups'];
        }
        $previous = $runs[$id] ?? null;
        $adjacent = $previous && $previous['end']===$a && $previous['boot']===$row['boot'];
        if ($adjacent && $previous['kind']===$kind) {
            $run=$previous; $run['seconds']+=$amount;
            $run['resolution']=max($run['resolution'],$row['interval_minutes']*60);
        } else {
            $run=['kind'=>$kind,'start'=>$a,'seconds'=>$amount,'boot'=>$row['boot'],
                'leftClosed'=>$adjacent && $previous['kind']==='awake' && ($last[$id]['state']??'')==='sleep',
                'resolution'=>$row['interval_minutes']*60];
        }
        $run['end']=$b;
        if ($kind==='sleep' && $row['state']==='awake' && $row['wakeups']>0
            && $run['leftClosed'] && $run['start']>=$start && $b<=$end) {
            $duration=$run['seconds'];
            $item['complete_count']++;
            if ($run['resolution']>=1800 && $duration<3600) $item['coarse']++;
            else {
                $bucket=$duration<1800?0:($duration<3600?1:($duration<10800?2:($duration<21600?3:4)));
                $item['distribution'][$bucket]++;
            }
        }
        $runs[$id]=$run; $last[$id]=$row; unset($item);
    }
    foreach ($series as &$item) {
        $known=$item['awake']+$item['sleep'];
        $item['ratio']=$known>0?$item['sleep']/$known:null;
    }
    unset($item);
    return ['start'=>$start,'end'=>$end,'granularity'=>$period['kind'],'bins'=>$bins,'series'=>array_values($series),'clock_gaps'=>$clock];
}
