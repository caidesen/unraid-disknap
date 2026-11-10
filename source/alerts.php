<?php
require_once __DIR__.'/database.php';
function dn_alerts(SQLite3 $db, array $config, array $data, callable $send): int {
    $options=dn_alert_config($config);
    if (!$options['alerts_enabled']) { $db->exec('DELETE FROM pending_alerts'); return 0; }
    $sent=0;
    $rows=dn_db_run($db,'SELECT s.disk_id,s.slot,s.interval_start,o.id AS latest
        FROM observations o JOIN disk_samples s ON s.observation_id=o.id
        WHERE o.boot=:boot AND o.uptime=:uptime AND s.wakeups>0',
        ['boot'=>$data['boot'],'uptime'=>$data['uptime']]);
    while($row=$rows->fetchArray(SQLITE3_ASSOC)){
        $history=dn_db_run($db,'SELECT s.state,s.spindowns,s.interval_start,o.sampled_at,o.boot,o.uptime
            FROM disk_samples s JOIN observations o ON o.id=s.observation_id
            WHERE s.disk_id=:id AND s.observation_id<:latest ORDER BY s.observation_id DESC',
            ['id'=>$row['disk_id'],'latest'=>$row['latest']]);
        $expected=$row['interval_start'];
        $duration=null;
        while($sleep=$history->fetchArray(SQLITE3_ASSOC)){
            $seconds=$data['uptime']-$sleep['uptime'];
            if($sleep['boot']!==$data['boot'] || $sleep['state']!=='sleep' || $expected===null
                || $expected!==$sleep['sampled_at'] || $seconds<=0 || $seconds>$options['short_sleep_minutes']*60)break;
            if($sleep['spindowns']>0 && $sleep['interval_start']!==null){$duration=$seconds;break;}
            $expected=$sleep['interval_start'];
        }
        $history->finalize();
        if($duration===null)continue;
        $last=dn_db_run($db,'SELECT * FROM alert_state WHERE disk_id=:id',['id'=>$row['disk_id']])->fetchArray(SQLITE3_ASSOC);
        if($last && $last['boot']===$data['boot'] && ($last['observation_id']>=$row['latest'] || $data['uptime']-$last['uptime']<$options['alert_cooldown_minutes']*60))continue;
        dn_db_run($db,'INSERT INTO pending_alerts(disk_id,boot,observation_id,duration) VALUES(:id,:boot,:observation,:duration)
            ON CONFLICT(disk_id) DO NOTHING',
            ['id'=>$row['disk_id'],'boot'=>$data['boot'],'observation'=>$row['latest'],'duration'=>$duration]);
    }
    dn_db_run($db,'DELETE FROM pending_alerts WHERE boot<>:boot OR duration>:duration',
        ['boot'=>$data['boot'],'duration'=>$options['short_sleep_minutes']*60]);
    $result=$db->query('SELECT p.*,d.slot FROM pending_alerts p JOIN disks d ON d.id=p.disk_id');
    $pending=[];
    while($row=$result->fetchArray(SQLITE3_ASSOC))$pending[]=$row;
    $result->finalize();
    $failed=false;
    foreach($pending as $row){
        $minutes=round($row['duration']/60,1);
        try {
            $ok=$send('DiskNap：硬盘休眠过短',$row['slot'].' 休眠约 '.$minutes.' 分钟后被唤醒',
                '硬盘：'.$row['slot'].'（'.$row['disk_id'].'）。休眠约 '.$minutes.' 分钟后被唤醒，请检查相关服务或定时任务。');
        } catch(Throwable $e) { $ok=false; }
        if(!$ok){$failed=true;continue;}
        $db->exec('BEGIN IMMEDIATE');
        try {
            dn_db_run($db,'INSERT INTO alert_state(disk_id,boot,uptime,observation_id) VALUES(:id,:boot,:uptime,:observation)
                ON CONFLICT(disk_id) DO UPDATE SET boot=excluded.boot,uptime=excluded.uptime,observation_id=excluded.observation_id',
                ['id'=>$row['disk_id'],'boot'=>$data['boot'],'uptime'=>$data['uptime'],'observation'=>$row['observation_id']]);
            dn_db_run($db,'DELETE FROM pending_alerts WHERE disk_id=:id',['id'=>$row['disk_id']]);
            $db->exec('COMMIT');
        } catch(Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        $sent++;
    }
    if($failed)throw new RuntimeException('Unraid 通知发送失败，将在下次采集任务重试');
    return $sent;
}
function dn_unraid_notify(string $subject,string $description,string $message): bool {
    $command='/usr/local/emhttp/webGui/scripts/notify';
    if(!is_executable($command))throw new RuntimeException('找不到 Unraid 系统通知工具');
    $process=proc_open([$command,'-e','DiskNap','-s',$subject,'-d',$description,'-m',$message,'-i','warning','-l','/DiskNapDetails'],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    return is_resource($process) && proc_close($process)===0;
}
