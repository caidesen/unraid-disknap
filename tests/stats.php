<?php
require __DIR__.'/../source/lib.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function disk($state, $id='HDD-A') { return ['type'=>'Data','rotational'=>'1','id'=>$id,'device'=>'sdb','status'=>'DISK_OK','spundown'=>$state]; }
$s = dn_sample(dn_empty(), ['disk1'=>disk('1')], 1000, 100, 'boot-a', 5);
check($s['disks']['HDD-A']['wakeups'] === 0, '首次观察不能算唤醒');
$s = dn_sample($s, ['disk1'=>disk('0')], 1300, 400, 'boot-a', 5);
check($s['disks']['HDD-A']['sleep_seconds'] === 300 && $s['disks']['HDD-A']['wakeups'] === 1, '休眠转运行');
$s = dn_sample($s, ['disk1'=>disk('1')], 1600, 700, 'boot-a', 5);
check($s['disks']['HDD-A']['awake_seconds'] === 300 && $s['disks']['HDD-A']['spindowns'] === 1, '运行转休眠');
$s = dn_sample($s, ['disk2'=>disk('0','HDD-B')], 1900, 1000, 'boot-a', 5);
check($s['disks']['HDD-A']['state'] === 'unknown' && $s['disks']['HDD-B']['wakeups'] === 0, '换盘独立统计');
$s = dn_sample($s, ['disk2'=>disk('1','HDD-B')], 2200, 50, 'boot-b', 5);
check($s['disks']['HDD-B']['spindowns'] === 0, '重启不计转换');
$s = dn_sample($s, ['disk2'=>disk('0','HDD-B')], 4000, 1850, 'boot-b', 5);
check($s['disks']['HDD-B']['wakeups'] === 0 && $s['disks']['HDD-B']['sleep_seconds'] === 0, '中断不累计');
$s = dn_sample($s, ['disk1'=>disk('')], 4300, 2150, 'boot-b', 5);
check($s['disks']['HDD-A']['state'] === 'unknown', '缺字段不是运行');
$s = dn_sample(dn_empty(), ['ssd'=>array_merge(disk('0'),['rotational'=>'0'])], 1, 1, 'x', 5);
check(!$s['disks'], '排除 SSD');
check(dn_interval([]) === 5 && dn_interval(['interval_minutes'=>60]) === 60, '默认和配置');
foreach ([0,61,'5',false] as $bad) {
    $rejected = false;
    try { dn_interval(['interval_minutes'=>$bad]); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, '拒绝无效配置');
}
$s = dn_sample(dn_empty(), ['disk1'=>disk('1')], 100, 100, 'x', 5);
$s = dn_sample($s, ['disk1'=>disk('0')], 700, 700, 'x', 10);
check($s['disks']['HDD-A']['sleep_seconds'] === 600, '更改采样间隔');
$s = dn_sample($s, ['disk1'=>disk('1')], 600, 1000, 'x', 5);
check($s['disks']['HDD-A']['awake_seconds'] === 300, '墙上时钟回拨使用 uptime 计时');
$dir = sys_get_temp_dir().'/disknap-test-'.bin2hex(random_bytes(8));
mkdir($dir);
try {
    dn_write($dir.'/stats.json', $s);
    check(dn_validate(dn_read($dir.'/stats.json', [])) === $s, '原子保存和恢复');
    file_put_contents($dir.'/stats.json', '{broken');
    $rejected = false;
    try { dn_read($dir.'/stats.json', []); } catch (JsonException $e) { $rejected = true; }
    check($rejected, '损坏文件不能静默归零');
} finally { unlink($dir.'/stats.json'); rmdir($dir); }
$s = dn_sample(dn_empty(), ['"disk1"'=>disk('1')], 100, 100, 'x', 5);
check($s['disks']['HDD-A']['slot'] === 'disk1', '真实 INI 盘位名称去除引号');

check(dn_database_path(['database_path'=>'/mnt/fast/appdata/disknap/history.sqlite']) === '/mnt/fast/appdata/disknap/history.sqlite', '其他池路径');
foreach (['/boot/stats.sqlite','/mnt/user/appdata/stats.sqlite','/mnt/user0/x.sqlite','/mnt/disk1/x.sqlite','/mnt/cache/../x.sqlite','/mnt/cache//x.sqlite',"/mnt/cache/a\n.sqlite",'/mnt/cache/a.txt','/mnt/cache/a\\b.sqlite',[]] as $invalid) {
    $rejected = false;
    try { dn_database_path(['database_path'=>$invalid]); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, '拒绝非法数据库路径');
}
foreach (["rootfs / rootfs rw 0 0\n", "tmpfs /mnt/cache tmpfs rw 0 0\n", "/dev/sdb1 /mnt/cache-other btrfs rw 0 0\n"] as $mounts) {
    $rejected = false;
    try { dn_storage_ready('/mnt/cache/appdata/disknap/stats.sqlite', $mounts); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, '拒绝未挂载或错误存储池');
}
echo "统计测试通过\n";
