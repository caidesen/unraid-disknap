<?php
require_once __DIR__.'/lib.php';

function dn_db_run(SQLite3 $db, string $sql, array $values = []): SQLite3Result {
    $statement = $db->prepare($sql);
    foreach ($values as $key => $value) {
        $type = $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : (is_float($value) ? SQLITE3_FLOAT : SQLITE3_TEXT));
        $statement->bindValue(':'.$key, $value, $type);
    }
    return $statement->execute();
}
function dn_db_open(string $path, bool $write = false): SQLite3 {
    $db = new SQLite3($path, $write ? SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE : SQLITE3_OPEN_READONLY);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys=ON');
    $version = $db->querySingle('PRAGMA user_version');
    if ($version === 0 && $write) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec("CREATE TABLE metadata (id INTEGER PRIMARY KEY CHECK(id=1), value TEXT NOT NULL);
                CREATE TABLE disks (
                    id TEXT PRIMARY KEY, slot TEXT NOT NULL, device TEXT NOT NULL,
                    state TEXT NOT NULL CHECK(state IN ('awake','sleep','unknown')),
                    awake_seconds INTEGER NOT NULL CHECK(awake_seconds>=0), sleep_seconds INTEGER NOT NULL CHECK(sleep_seconds>=0),
                    wakeups INTEGER NOT NULL CHECK(wakeups>=0), spindowns INTEGER NOT NULL CHECK(spindowns>=0),
                    first_seen INTEGER NOT NULL, seen_at INTEGER NOT NULL);
                CREATE TABLE observations (
                    id INTEGER PRIMARY KEY, sampled_at INTEGER NOT NULL, boot TEXT NOT NULL,
                    uptime REAL NOT NULL, interval_minutes INTEGER NOT NULL, UNIQUE(boot,uptime));
                CREATE INDEX observations_time ON observations(sampled_at);
                CREATE TABLE disk_samples (
                    observation_id INTEGER NOT NULL REFERENCES observations(id), disk_id TEXT NOT NULL REFERENCES disks(id),
                    slot TEXT NOT NULL, device TEXT NOT NULL, state TEXT NOT NULL,
                    interval_start INTEGER, awake_seconds INTEGER NOT NULL, sleep_seconds INTEGER NOT NULL,
                    wakeups INTEGER NOT NULL, spindowns INTEGER NOT NULL,
                    PRIMARY KEY(observation_id,disk_id));
                CREATE INDEX disk_samples_disk ON disk_samples(disk_id,observation_id);
                PRAGMA user_version=1;");
            $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        $version = 1;
    }
    if ($version===1 && $write) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS alert_state(disk_id TEXT PRIMARY KEY,boot TEXT NOT NULL,uptime REAL NOT NULL,observation_id INTEGER NOT NULL); PRAGMA user_version=2;');
            $db->exec('COMMIT'); $version=2;
        } catch(Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }
    if ($version===2 && $write) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec('CREATE TABLE pending_alerts(disk_id TEXT PRIMARY KEY,boot TEXT NOT NULL,observation_id INTEGER NOT NULL,duration REAL NOT NULL); PRAGMA user_version=3;');
            $db->exec('COMMIT'); $version=3;
        } catch(Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }
    if (!in_array($version,[1,2,3],true)) throw new RuntimeException('不支持的统计数据库版本：'.$version);
    if ($write) $db->exec('PRAGMA synchronous=FULL');
    return $db;
}
function dn_db_load(SQLite3 $db): ?array {
    $db->exec('BEGIN');
    try {
        $metadata = $db->querySingle('SELECT value FROM metadata WHERE id=1');
        if ($metadata === null) { $db->exec('COMMIT'); return null; }
        $data = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        unset($data['checkpoint_at']);
        $data['disks'] = [];
        $rows = $db->query('SELECT * FROM disks');
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $id = $row['id']; unset($row['id']);
            unset($row['transition_at']);
            $data['disks'][$id] = $row;
        }
        $data = dn_validate($data);
        $db->exec('COMMIT');
        return $data;
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
function dn_db_save(SQLite3 $db, array $data, ?array $previous = null): void {
    dn_validate($data);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $metadata = $data; unset($metadata['disks']);
        dn_db_run($db, 'INSERT INTO metadata VALUES(1,:value) ON CONFLICT(id) DO UPDATE SET value=excluded.value',
            ['value'=>json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
        $columns = ['slot','device','state','awake_seconds','sleep_seconds','wakeups','spindowns','first_seen','seen_at'];
        $updates = implode(',', array_map(static function ($c) { return "$c=excluded.$c"; }, $columns));
        foreach ($data['disks'] as $id => $row) {
            $values = ['id'=>(string)$id];
            foreach ($columns as $column) $values[$column] = $row[$column] ?? null;
            dn_db_run($db, 'INSERT INTO disks ('.implode(',', array_keys($values)).') VALUES (:'.implode(',:', array_keys($values)).') ON CONFLICT(id) DO UPDATE SET '.$updates, $values);
        }
        if ($previous !== null) {
            dn_db_run($db, 'INSERT INTO observations(sampled_at,boot,uptime,interval_minutes) VALUES(:sampled_at,:boot,:uptime,:interval_minutes)',
                ['sampled_at'=>$data['sample_at'],'boot'=>$data['boot'],'uptime'=>$data['uptime'],'interval_minutes'=>$data['interval_minutes']]);
            $observation = $db->lastInsertRowID();
            foreach ($data['disks'] as $id => $row) {
                $old = $previous['disks'][$id] ?? [];
                $values = ['observation_id'=>$observation,'disk_id'=>(string)$id,'slot'=>$row['slot'],'device'=>$row['device'],'state'=>$row['state']];
                foreach (['awake_seconds','sleep_seconds','wakeups','spindowns'] as $key) $values[$key] = $row[$key] - ($old[$key] ?? 0);
                $values['interval_start'] = $values['awake_seconds'] + $values['sleep_seconds'] > 0 ? $previous['sample_at'] : null;
                dn_db_run($db, 'INSERT INTO disk_samples ('.implode(',', array_keys($values)).') VALUES (:'.implode(',:', array_keys($values)).')', $values);
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

function dn_db_copy(string $source, string $target): void {
    if (file_exists($target) || is_link($target)) throw new RuntimeException('目标文件已存在，请选择新的数据库文件名，避免覆盖数据');
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('无法创建目标目录');
    $temporary = tempnam($dir, '.disknap-move-');
    if ($temporary === false) throw new RuntimeException('无法创建迁移临时文件');
    $sourceDb = $targetDb = null;
    try {
        $sourceDb = dn_db_open($source);
        $targetDb = new SQLite3($temporary);
        $targetDb->enableExceptions(true);
        if (!$sourceDb->backup($targetDb) || $targetDb->querySingle('PRAGMA integrity_check') !== 'ok'
            || dn_db_load($sourceDb) != dn_db_load($targetDb)
            || $sourceDb->querySingle('SELECT count(*) FROM observations') !== $targetDb->querySingle('SELECT count(*) FROM observations')
            || $sourceDb->querySingle('SELECT count(*) FROM disk_samples') !== $targetDb->querySingle('SELECT count(*) FROM disk_samples')) throw new RuntimeException('数据库迁移校验失败');
        $sourceDb->close(); $sourceDb = null;
        $targetDb->close(); $targetDb = null;
        // 硬链接发布不会覆盖并发创建的同名文件。
        if (!chmod($temporary, 0644) || !link($temporary, $target)) throw new RuntimeException('无法发布迁移后的数据库');
    } finally {
        if ($sourceDb) $sourceDb->close();
        if ($targetDb) $targetDb->close();
        if (file_exists($temporary)) unlink($temporary);
    }
}

function dn_db_recent(SQLite3 $db): array {
    $result = $db->query('SELECT o.sampled_at,s.disk_id,s.slot,s.state,s.interval_start,s.awake_seconds,s.sleep_seconds,s.wakeups,s.spindowns
        FROM disk_samples s JOIN observations o ON o.id=s.observation_id
        ORDER BY s.observation_id DESC,s.disk_id DESC LIMIT 50');
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}
