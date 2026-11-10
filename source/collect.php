<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/alerts.php';
function dn_collect(SQLite3 $db,array $config,array $disks,int $now,float $uptime,string $boot,callable $send): int {
    $data = dn_db_load($db);
    if ($data === null) {
        $data = dn_empty();
        dn_db_save($db, $data);
    }
    $interval = dn_interval($config);
    if (($data['boot'] ?? '') === $boot && $uptime >= ($data['uptime'] ?? 0)
        && (int)floor($uptime / 60) - (int)floor(($data['uptime'] ?? 0) / 60) < $interval) {
        return dn_alerts($db,$config,['boot'=>$boot,'uptime'=>$uptime],$send);
    }
    $next = dn_sample($data,$disks,$now,$uptime,$boot,$interval);
    dn_db_save($db,$next,$data);
    return dn_alerts($db,$config,$next,$send);
}
if (realpath($_SERVER['SCRIPT_FILENAME'])!==__FILE__) return;
try {
    $runtime = '/var/local/disknap';
    $configDir = '/boot/config/plugins/disknap';
    foreach ([$runtime] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('无法创建目录：'.$dir);
    }
    $lock = fopen($runtime.'/collect.lock', 'c');
    if (!$lock) throw new RuntimeException('无法创建采样锁');
    if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
    $config = dn_read($configDir.'/config.json', []);
    $path = dn_database_path($config);
    dn_storage_ready($path, file_get_contents('/proc/self/mounts'));
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('无法创建数据库目录');
    $db = dn_db_open($path, true);
    $boot = trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
    $uptime = (float)explode(' ', file_get_contents('/proc/uptime'))[0];
    if (!$boot || $uptime <= 0) throw new RuntimeException('无法读取系统启动信息');
    $disks = parse_ini_file('/var/local/emhttp/disks.ini', true, INI_SCANNER_RAW);
    if ($disks === false || !$disks) throw new RuntimeException('无法读取 Unraid 磁盘状态');
    dn_collect($db,$config,$disks,time(),$uptime,$boot,'dn_unraid_notify');
} catch (Throwable $e) {
    error_log('DiskNap: '.$e->getMessage());
    openlog('disknap', LOG_PID, LOG_USER);
    syslog(LOG_ERR, $e->getMessage());
    exit(1);
}
