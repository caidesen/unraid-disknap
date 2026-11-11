<?php
function dn_read(string $path, array $fallback): array {
    if (!file_exists($path)) return $fallback;
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('无效的 JSON：'.$path);
    return $data;
}
function dn_write(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
    $tmp = tempnam(dirname($path), '.disknap-');
    if ($tmp === false) throw new RuntimeException('无法创建临时文件');
    try {
        if (file_put_contents($tmp, $json) !== strlen($json) || !chmod($tmp, 0644) || !rename($tmp, $path)) throw new RuntimeException('无法保存：'.$path);
    } finally { if (file_exists($tmp)) unlink($tmp); }
}
function dn_auto_database_path(array $pools,array $disks,array $appdata,string $mounts): string {
    usort($pools,static function($a,$b){return strlen($b)-strlen($a);});
    $eligible=[];
    foreach($pools as $pool){
        if(!preg_match('/^[A-Za-z0-9_.-]+$/D',$pool) || in_array($pool,['.','..','user','user0'],true)
            || preg_match('/^disk[0-9]+$/D',$pool))continue;
        if(preg_match('~^\\S+ /mnt/'.preg_quote($pool,'~').' (?!tmpfs |rootfs )\\S+ ~m',$mounts)!==1)continue;
        $members=[];
        foreach($disks as $slot=>$disk){
            if(!is_array($disk) || ($disk['type']??'')!=='Cache')continue;
            $name=$disk['name']??trim((string)$slot,'"');
            foreach($pools as $candidate){
                if(preg_match('/^'.preg_quote($candidate,'/').'[0-9]*$/D',$name)){
                    if($candidate===$pool)$members[]=$disk;
                    break;
                }
            }
        }
        if(!$members)continue;
        $ssd=true;
        foreach($members as $disk){
            if((string)($disk['rotational']??'')!=='0' || empty($disk['device']) || ($disk['status']??'')!=='DISK_OK')$ssd=false;
        }
        if($ssd)$eligible[]=$pool;
    }
    $preferred=$appdata['shareCachePool']??'';
    if(in_array($preferred,$eligible,true))$pool=$preferred;
    elseif(count($eligible)===1)$pool=$eligible[0];
    else throw new RuntimeException('无法自动确定 SSD 存储池，请在 DiskNap 设置中填写数据文件位置');
    return '/mnt/'.$pool.'/appdata/disknap/stats.sqlite';
}
function dn_database_path(array $config): string {
    $path = $config['database_path'] ?? null;
    if($path===null){
        $legacy='/mnt/cache/appdata/disknap/stats.sqlite';
        if(is_file($legacy))$path=$legacy;
        else {
            $pools=array_map(static function($file){return basename($file,'.cfg');},glob('/boot/config/pools/*.cfg')?:[]);
            $disks=is_file('/var/local/emhttp/disks.ini')?parse_ini_file('/var/local/emhttp/disks.ini',true,INI_SCANNER_RAW):[];
            $appdata=is_file('/boot/config/shares/appdata.cfg')?parse_ini_file('/boot/config/shares/appdata.cfg',false,INI_SCANNER_RAW):[];
            $path=dn_auto_database_path($pools,$disks?:[],$appdata?:[],file_get_contents('/proc/self/mounts'));
        }
    }
    if (!is_string($path) || preg_match('~^/mnt/([A-Za-z0-9_.-]+)/.+\\.sqlite$~D', $path, $match) !== 1
        || in_array($match[1], ['user','user0'], true) || preg_match('~^disk[0-9]+$~D', $match[1])
        || preg_match('/[\\x00-\\x1f\\x7f\\\\]/', $path)) throw new RuntimeException('数据库须使用 /mnt/池名/目录/文件.sqlite 的直接路径，请选择 SSD 池');
    foreach (explode('/', substr($path, 1)) as $part) {
        if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('数据库路径不能包含空目录、. 或 ..');
    }
    return $path;
}
function dn_storage_ready(string $path, string $mounts): void {
    $pool = '/mnt/'.explode('/', $path)[2];
    if (preg_match('~^\\S+ '.preg_quote($pool, '~').' (?!tmpfs |rootfs )\\S+ ~m', $mounts) !== 1) throw new RuntimeException('数据库所在存储池未挂载：'.$pool);
    $ancestor = $path;
    while (!file_exists($ancestor) && !is_link($ancestor)) $ancestor = dirname($ancestor);
    $real = realpath($ancestor);
    if ($real === false || realpath($pool) !== $pool || ($real !== $pool && strpos($real, $pool.'/') !== 0)) throw new RuntimeException('数据库路径不能通过符号链接指向池外');
}
function dn_interval(array $config): int {
    $n = $config['interval_minutes'] ?? 5;
    if (!is_int($n) || $n < 1 || $n > 60) throw new RuntimeException('采样间隔必须为 1–60 分钟的整数');
    return $n;
}
function dn_empty(): array { return ['version'=>1,'disks'=>[],'sample_at'=>0]; }
function dn_validate(array $data): array {
    if (($data['version'] ?? null) !== 1 || !isset($data['disks']) || !is_array($data['disks'])) throw new RuntimeException('统计文件损坏，已停止写入以保护历史');
    if (!isset($data['sample_at']) || !is_int($data['sample_at']) || $data['sample_at'] < 0) throw new RuntimeException('统计时间损坏');
    foreach ($data['disks'] as $row) {
        if (!is_array($row)) throw new RuntimeException('统计记录损坏');
        if (!in_array($row['state'] ?? '', ['awake','sleep','unknown'], true) || !is_string($row['slot'] ?? null) || !is_string($row['device'] ?? null)) throw new RuntimeException('统计设备信息损坏');
        foreach (['awake_seconds','sleep_seconds','wakeups','spindowns'] as $key) {
            if (!isset($row[$key]) || !is_int($row[$key]) || $row[$key] < 0) throw new RuntimeException('统计计数损坏');
        }
    }
    return $data;
}
function dn_sample(array $data, array $disks, int $now, float $uptime, string $boot, int $interval): array {
    $elapsed = $uptime - ($data['uptime'] ?? $uptime);
    $continuous = ($data['boot'] ?? '') === $boot && $elapsed > 0
        && $elapsed <= max($interval, $data['interval_minutes'] ?? $interval) * 60 + 90;
    $previous = $data['disks'];
    foreach ($data['disks'] as &$row) $row['state'] = 'unknown';
    unset($row);
    foreach ($disks as $slot => $disk) {
        if (!is_array($disk) || !in_array($disk['type'] ?? '', ['Data','Parity','Cache'], true)
            || (string)($disk['rotational'] ?? '') !== '1' || empty($disk['id']) || empty($disk['device'])
            || strpos($disk['status'] ?? '', 'DISK_NP') === 0) continue;
        $id = $disk['id'];
        $old = $previous[$id] ?? null;
        $row = $old ?? ['awake_seconds'=>0,'sleep_seconds'=>0,'wakeups'=>0,'spindowns'=>0,'first_seen'=>$now];
        $flag = (string)($disk['spundown'] ?? '');
        $state = $flag === '1' ? 'sleep' : ($flag === '0' ? 'awake' : 'unknown');
        if ($continuous && $old && in_array($old['state'], ['awake','sleep'], true) && $state !== 'unknown') {
            // shortcut: 区间按上次状态估算，需捕获区间内短暂唤醒时改用事件源。
            $row[$old['state'] === 'awake' ? 'awake_seconds' : 'sleep_seconds'] += (int)floor($elapsed);
            if ($old['state'] !== $state) {
                $row[$state === 'awake' ? 'wakeups' : 'spindowns']++;
            }
        }
        $row['state'] = $state;
        $row['slot'] = trim((string)$slot, '"');
        $row['device'] = $disk['device'];
        $row['seen_at'] = $now;
        $data['disks'][$id] = $row;
    }
    $data['sample_at'] = $now;
    $data['uptime'] = $uptime;
    $data['boot'] = $boot;
    $data['interval_minutes'] = $interval;
    return $data;
}

function dn_alert_config(array $config): array {
    $enabled=$config['alerts_enabled']??true;
    $threshold=$config['short_sleep_minutes']??10;
    $cooldown=$config['alert_cooldown_minutes']??60;
    if(!is_bool($enabled) || !is_int($threshold) || $threshold<1 || $threshold>1440
        || !is_int($cooldown) || $cooldown<1 || $cooldown>1440)throw new RuntimeException('请检查通知开关、短休眠时长（1–1440 分钟）和通知间隔（1–1440 分钟）');
    return ['alerts_enabled'=>$enabled,'short_sleep_minutes'=>$threshold,'alert_cooldown_minutes'=>$cooldown];
}
