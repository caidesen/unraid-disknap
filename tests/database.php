<?php
require __DIR__.'/../source/database.php';
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function fixture($flag) { return ['disk1'=>['type'=>'Data','rotational'=>'1','id'=>"HDD'A",'device'=>'sdb','status'=>'DISK_OK','spundown'=>$flag]]; }
$dir = sys_get_temp_dir().'/disknap-db-test-'.bin2hex(random_bytes(8));
mkdir($dir);
try {
    $db = dn_db_open($dir.'/test.sqlite', true);
    verify(dn_db_load($db) === null, '新库尚未迁移');
    $legacy = dn_sample(dn_empty(), fixture('1'), 1000, 100, 'boot-a', 5);
    dn_db_save($db, $legacy);
    verify(dn_db_load($db) == $legacy, '迁移保留全部累计值和采样元数据');
    verify($db->querySingle('SELECT count(*) FROM observations') === 0, '不能伪造迁移前历史');
    $oldMetadata=$db->querySingle('SELECT value FROM metadata');
    $oldData=json_decode($oldMetadata,true);$oldData['checkpoint_at']=123;
    dn_db_run($db,'UPDATE metadata SET value=:value',['value'=>json_encode($oldData)]);
    $db->exec('ALTER TABLE disks ADD COLUMN transition_at INTEGER; UPDATE disks SET transition_at=1000');
    verify(dn_db_load($db)==$legacy,'旧字段忽略，不进入运行状态');
    $next = dn_sample(dn_db_load($db), fixture('0'), 1300, 400, 'boot-a', 5);
    dn_db_save($db, $next, $legacy);
    $history = $db->querySingle('SELECT * FROM disk_samples', true);
    verify($history['sleep_seconds'] === 300 && $history['wakeups'] === 1 && $history['interval_start'] === 1000, '保存区间时长及唤醒增量');
    $duplicate = $next;
    $duplicate['disks']["HDD'A"]['awake_seconds'] = 999;
    $failed = false;
    try { dn_db_save($db, $duplicate, $next); } catch (Throwable $e) { $failed = true; }
    verify($failed && dn_db_load($db) == $next, '重复采样导致事务回滚，不能部分更新累计值');
    verify($db->querySingle('SELECT count(*) FROM observations') === 1, '重复采样不能增加历史');
    $reboot = dn_sample($next, fixture('1'), 2000, 10, 'boot-b', 5);
    dn_db_save($db, $reboot, $next);
    $gap = $db->querySingle('SELECT * FROM disk_samples ORDER BY observation_id DESC LIMIT 1', true);
    verify($gap['awake_seconds'] === 0 && $gap['spindowns'] === 0 && $gap['interval_start'] === null, '重启不能计入时长或转换');
    $offline = dn_sample($reboot, [], 2300, 310, 'boot-b', 5);
    dn_db_save($db, $offline, $reboot);
    verify($db->querySingle('SELECT state FROM disk_samples ORDER BY observation_id DESC LIMIT 1') === 'unknown', '保留设备离线状态');
    verify($db->querySingle('SELECT sum(sleep_seconds) FROM disk_samples') === 300, '历史增量可直接汇总');
    verify($db->querySingle('PRAGMA integrity_check') === 'ok', '数据库完整性');
    $recent = dn_db_recent($db);
    verify(count($recent) === 3 && $recent[0]['state'] === 'unknown' && $recent[2]['wakeups'] === 1, '最近采样按顺序返回并保留增量');
    $db->close();
    $db = dn_db_open($dir.'/test.sqlite');
    verify(dn_db_load($db) == $offline, '关闭重开从数据库恢复，忽略旧 JSON');
    $failed = false;
    try { dn_db_save($db, $next); } catch (Throwable $e) { $failed = true; }
    verify($failed, '页面连接不能写入数据库');
    $db->close();
    dn_db_copy($dir.'/test.sqlite', $dir.'/moved.sqlite');
    $copy = dn_db_open($dir.'/moved.sqlite');
    verify(dn_db_load($copy) == $offline && $copy->querySingle('SELECT count(*) FROM observations') === 3, '迁移保留累计值及全部历史');
    $copy->close();
    verify(file_exists($dir.'/test.sqlite'), '配置提交前保留原数据库');
    $hash = hash_file('sha256', $dir.'/moved.sqlite');
    $failed = false;
    try { dn_db_copy($dir.'/test.sqlite', $dir.'/moved.sqlite'); } catch (Throwable $e) { $failed = true; }
    verify($failed && hash_file('sha256', $dir.'/moved.sqlite') === $hash, '不覆盖现有目标');
    $failed = false;
    try { dn_db_copy($dir.'/missing.sqlite', $dir.'/failed.sqlite'); } catch (Throwable $e) { $failed = true; }
    verify($failed && !file_exists($dir.'/failed.sqlite'), '失败迁移不能留下目标数据库');
    $db = new SQLite3($dir.'/future.sqlite'); $db->exec('PRAGMA user_version=4'); $db->close();
    $failed = false;
    try { dn_db_open($dir.'/future.sqlite', true); } catch (Throwable $e) { $failed = true; }
    verify($failed, '拒绝未知 schema 版本，不能静默重建');
    $db = dn_db_open($dir.'/test.sqlite', true);
    $latest = dn_db_load($db);
    for ($i=1; $i<=51; $i++) {
        $sample = dn_sample($latest, fixture('0'), 2300+$i*300, 310+$i*300, 'boot-b', 5);
        dn_db_save($db, $sample, $latest);
        $latest = $sample;
    }
    $recent = dn_db_recent($db);
    verify(count($recent) === 50 && $recent[0]['sampled_at'] === 17600 && $recent[49]['sampled_at'] === 2900, '历史查询仅返回最新 50 条');
    $db->exec('DROP TABLE pending_alerts; PRAGMA user_version=2');
    $db->close();$db=dn_db_open($dir.'/test.sqlite',true);
    verify($db->querySingle('PRAGMA user_version')===3 && dn_db_load($db)==$latest
        && $db->querySingle('SELECT count(*) FROM pending_alerts')===0,'旧版本自动升级且保留历史');
    verify(!array_key_exists('checkpoint_at',json_decode($db->querySingle('SELECT value FROM metadata'),true)),'旧检查点不再写回');
    $db->close();
    echo "SQLite 测试通过\n";
} finally {
    foreach (glob($dir.'/*') as $file) unlink($file);
    rmdir($dir);
}
