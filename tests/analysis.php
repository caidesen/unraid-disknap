<?php
require __DIR__.'/../source/analysis.php';
function expect($condition,$message) { if(!$condition) throw new RuntimeException($message); }
function device($state) { return ['disk1'=>['type'=>'Data','rotational'=>'1','id'=>'test-disk','device'=>'sdb','status'=>'DISK_OK','spundown'=>$state]]; }
$previousTimezone=date_default_timezone_get(); date_default_timezone_set('UTC');
$dir=sys_get_temp_dir().'/disknap-analysis-'.bin2hex(random_bytes(8)); mkdir($dir);
try {
    $db=dn_db_open($dir.'/test.sqlite',true);
    $t=strtotime('2026-11-09 23:50:00');
    $state=dn_sample(dn_empty(),device('0'),$t,100,'boot',5); dn_db_save($db,$state);
    foreach([300=>'1',600=>'1',900=>'0',1200=>'0',1500=>'',1800=>'1',2100=>'0',2400=>'0'] as $offset=>$flag) {
        $next=dn_sample($state,device($flag),$t+$offset,100+$offset,'boot',5);
        dn_db_save($db,$next,$state); $state=$next;
    }
    $analysis=dn_analysis($db,$state['disks'],$t,$t+2401); $s=$analysis['series'][0];
    expect($s['awake']==900 && $s['sleep']==900,'未知和中断不计入有效时长');
    expect($s['wakes']===2 && $s['distribution']===[1,0,0,0,0] && $s['complete_count']===1,'只统计两端完整的休眠段');
    expect($s['periods'][0]['awake']+$s['periods'][0]['sleep']==600 && $s['periods'][1]['awake']+$s['periods'][1]['sleep']==1200,'跨午夜按时间桶裁切');
    expect($s['periods'][1]['wakes']===2 && $s['periods'][0]['wakes']===0,'唤醒次数归入对应日期小时');
    expect(array_sum(array_column($s['periods'],'sleep'))==$s['sleep'] && array_sum(array_column($s['periods'],'wakes'))===$s['wakes'],'趋势汇总与窗口指标一致');
    expect($s['ratio']===0.5,'休眠率只使用已观测时长');
    expect($analysis['bins'][0]['label']==='23:00','小时横轴使用整点，部分区间仍按实际边界计算');
    $crop=dn_analysis($db,$state['disks'],$t+450,$t+1000)['series'][0];
    expect($crop['sleep']==450 && $crop['awake']==100 && $crop['complete_count']===0,'窗口两端裁切且排除不完整段');
    $days=dn_analysis($db,$state['disks'],$t-86400,$t+2401);
    expect(count($days['series'])===1 && $days['series'][0]['sleep']==900,'较长窗口保持同样的有效区间总量');
    foreach([1=>'hour',7=>'day',30=>'day',90=>'week',365=>'month',1095=>'year'] as $days=>$kind){
        $period=dn_analysis_bins($t,$t+$days*86400);
        expect($period['kind']===$kind, '时间范围选择正确粒度');
        expect(array_sum(array_map(static function($b){return $b['end']-$b['start'];},$period['bins']))===$days*86400,'周期没有重叠或空隙');
        expect(count($period['bins'])<=46,'长期范围保持有限坐标点');
    }
    $weeks=dn_analysis_bins($t,$t+90*86400);
    expect(date('N',$weeks['bins'][1]['start'])==='1','周桶以周一开始');
    $months=dn_analysis_bins(strtotime('2023-08-30'),strtotime('2024-08-29'));
    $feb=array_values(array_filter($months['bins'],static function($b){return $b['label']==='2024-02';}))[0];
    expect($feb['end']-$feb['start']===29*86400,'闰年月份边界正确');
    date_default_timezone_set('America/New_York');
    $dst=dn_analysis_bins(strtotime('2026-11-01'),strtotime('2026-11-08'));
    expect($dst['bins'][0]['end']-$dst['bins'][0]['start']===25*3600,'按本地日期处理夏令时');
    date_default_timezone_set('UTC');
    $empty=dn_analysis($db,[],$t,$t+3600); expect(!$empty['series'],'空盘集合不生成假指标');
    $before=dn_analysis($db,$state['disks'],$t-7200,$t-3600)['series'][0];
    expect($before['ratio']===null,'纳入监控前无数据不能显示为零运行');
    $next=dn_sample($state,device('1'),$t+2700,2800,'new-boot',5); dn_db_save($db,$next,$state); $state=$next;
    $next=dn_sample($state,device('0'),$t+3000,3100,'new-boot',5); dn_db_save($db,$next,$state); $state=$next;
    $after=dn_analysis($db,$state['disks'],$t,$t+3001)['series'][0];
    expect($after['complete_count']===1,'跨重启不能组成完整睡眠段');
    $next=dn_sample($state,device('1'),$t+9000,3400,'new-boot',5); dn_db_save($db,$next,$state);
    $clock=dn_analysis($db,$next['disks'],$t,$t+9001);
    expect($clock['clock_gaps']===1,'墙上时间跳跃从区间统计排除');
    $state=$next;
    foreach([3300=>3700,3600=>4000] as $wall=>$up) {
        $next=dn_sample($state,device('0'),$t+$wall,$up,'new-boot',5); dn_db_save($db,$next,$state); $state=$next;
    }
    $rewind=dn_analysis($db,$state['disks'],$t,$t+9001);
    expect($rewind['clock_gaps']===3 && $rewind['series'][0]['awake']===$clock['series'][0]['awake'], '回拨后重叠区间不重复计入');
    $coarse=dn_db_open($dir.'/coarse.sqlite',true);
    $state=dn_sample(dn_empty(),device('0'),$t,100,'coarse',30); dn_db_save($coarse,$state);
    foreach([1800=>'1',3600=>'0'] as $offset=>$flag) {
        $next=dn_sample($state,device($flag),$t+$offset,100+$offset,'coarse',30); dn_db_save($coarse,$next,$state); $state=$next;
    }
    $coarseData=dn_analysis($coarse,$state['disks'],$t,$t+3601)['series'][0];
    expect($coarseData['coarse']===1 && array_sum($coarseData['distribution'])===0,'粗采样不能伪造短休眠分布');
    $coarse->close();
    $invalid=false; try {dn_analysis($db,[],$t,$t);} catch(RuntimeException $e){$invalid=true;}
    expect($invalid,'拒绝反向或空时间范围');
    expect(dn_analysis($db,[],$t-3*365*86400,$t)['start']===$t-3*365*86400,'允许长期查询');
    foreach([[$t,$t+2401],[$t+450,$t+1000],[$t,$t+9001],[$t-7200,$t-3600]] as [$from,$to]) {
        $full=dn_analysis($db,['test-disk'=>['slot'=>'disk1']],$from,$to);
        $summary=dn_analysis($db,['test-disk'=>['slot'=>'disk1']],$from,$to,true);
        foreach(['awake','sleep','wakes','ratio'] as $key)expect($summary['series'][0][$key]===$full['series'][0][$key],'概览与详情一致：'.$key);
        expect(!$summary['bins'] && !$summary['series'][0]['periods'],'概览不构建图表时间桶');
    }
    $plan=dn_db_run($db,'EXPLAIN QUERY PLAN SELECT o.id FROM observations AS o INDEXED BY observations_time CROSS JOIN disk_samples AS s ON s.observation_id=o.id WHERE o.sampled_at>=:start AND s.disk_id=:disk',['start'=>$t,'disk'=>'test-disk']);
    $first=$plan->fetchArray(SQLITE3_ASSOC);
    expect(strpos($first['detail'],'observations_time')!==false,'查询先按时间索引筛选');
    $db->close(); echo "分析聚合测试通过\n";
} finally {foreach(glob($dir.'/*') as $file) unlink($file);rmdir($dir);date_default_timezone_set($previousTimezone);}
