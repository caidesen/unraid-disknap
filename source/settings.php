<?php
require __DIR__.'/database.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$vars = parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW);
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || empty($vars['csrf_token']) || !hash_equals($vars['csrf_token'], $token)) {
    http_response_code(403); exit('请求校验失败，请从 Unraid 设置页重试。');
}
try {
    $lock = fopen('/var/local/disknap/collect.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('无法获得数据库迁移锁');
    $configPath = '/boot/config/plugins/disknap/config.json';
    $old = dn_read($configPath, []);
    $oldPath = dn_database_path($old);
    $config = ['interval_minutes'=>filter_var($_POST['interval_minutes'] ?? null, FILTER_VALIDATE_INT),
        'database_path'=>$_POST['database_path'] ?? $oldPath];
    $config += array_key_exists('alerts_form',$_POST) ? dn_alert_config([
        'alerts_enabled'=>isset($_POST['alerts_enabled']),
        'short_sleep_minutes'=>filter_var($_POST['short_sleep_minutes']??null,FILTER_VALIDATE_INT),
        'alert_cooldown_minutes'=>filter_var($_POST['alert_cooldown_minutes']??null,FILTER_VALIDATE_INT)]) : dn_alert_config($old);
    $newPath = dn_database_path($config);
    dn_storage_ready($newPath, file_get_contents('/proc/self/mounts'));
    dn_interval($config);
    if ($oldPath !== $newPath) {
        dn_storage_ready($oldPath, file_get_contents('/proc/self/mounts'));
        dn_db_copy($oldPath, $newPath);
    }
    try { dn_write($configPath, $config); }
    catch (Throwable $e) {
        if ($oldPath !== $newPath) unlink($newPath);
        throw $e;
    }
    if ($oldPath !== $newPath && !unlink($oldPath)) error_log('DiskNap: 新数据库已启用，旧数据库未能删除：'.$oldPath);
    header('Location: /Settings/DiskNapSettings', true, 303);
} catch (Throwable $e) { http_response_code(400); echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'); }
