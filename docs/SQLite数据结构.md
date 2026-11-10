# SQLite 数据结构

数据库默认位置：`/mnt/cache/appdata/disknap/stats.sqlite`（可在设置中修改），schema 版本通过 `PRAGMA user_version` 管理，目前为 3。

## 表

| 表 | 用途 |
|---|---|
| `metadata` | 单行 JSON 元数据：最近采样时间、boot ID、uptime、采样间隔。无需查询统计的字段保留原生结构 |
| `disks` | 每块盘当前状态、盘位、设备名、累计运行/休眠秒数、唤醒/休眠次数及首次/最近观察时间 |
| `observations` | 每轮采样的时间戳、boot ID、uptime 和采样间隔；boot ID 与 uptime 联合唯一，防止重复采样 |
| `pending_alerts` | 每盘一条待发提醒，包含启动标识、原唤醒轮次及休眠时长，失败后继续重试 |
| `alert_state` | 每盘最近成功通知的 boot ID、uptime 和采样轮次，用于冷却及防止重复提醒 |
| `disk_samples` | 每轮每块盘的状态、盘位、设备名、区间起点及本次运行/休眠秒数、唤醒/休眠次数增量 |

`disk_samples` 的时长和次数是增量，可以汇总。`disks` 中为累计值，旧的迁移基数已清理。首次采样、重启或未知区间没有有效统计时长时，`interval_start` 为空。

## 查询示例

按采样结束时间的本地日期汇总每天观察到的唤醒次数：

```sql
SELECT date(o.sampled_at, 'unixepoch', 'localtime') AS day,
       s.disk_id, SUM(s.wakeups) AS wakeups
FROM disk_samples s JOIN observations o ON o.id = s.observation_id
GROUP BY day, s.disk_id
ORDER BY day;
```

查询观察到的唤醒记录：

```sql
SELECT o.sampled_at, s.disk_id, s.slot, s.device
FROM disk_samples s JOIN observations o ON o.id = s.observation_id
WHERE s.wakeups > 0
ORDER BY o.sampled_at DESC;
```

跨午夜的时长不能简单全部归给采样结束当天；后续日汇总应按区间边界分配。系统时钟跳变会影响墙上时间，累计时长仍依据 uptime；未知和中断不应作为零运行或零休眠解释。

## 一致性

- 累计值、采样轮次和设备增量在同一事务提交，任一步失败会回滚。
- 采用 SQLite 默认回滚日志模式和 `synchronous=FULL`；只读页面在一个读事务中加载一致快照。
- 无额外数据库服务、ORM、WAL 管理或定时快照任务。
- 备份正在使用的数据库应使用 SQLite 在线备份接口或命令行 `.backup`，不要复制正在写入的数据库文件。
- 不自动清理历史，不提供图表或任意 SQL 的网页执行入口。

参考：[SQLite 原子提交](https://www.sqlite.org/atomiccommit.html)、[PHP SQLite3](https://www.php.net/manual/en/class.sqlite3.php)。

## 通知状态

写连接依次将 schema 1 升级至 2（新增 `alert_state`）、将 2 升级至 3（新增 `pending_alerts`），各步使用事务并保留历史。只读查询兼容版本 1、2、3。待发提醒持久化后再发送；失败保留待发项，每分钟任务继续重试。成功后在同一事务更新冷却及删除待发项。关闭通知或重启清除待发项。发送成功后若进程恰好在保存冷却前退出，可能重复提醒。

旧的 `checkpoint_at` 不再保存到元数据；`transition_at` 不再生成或写入，旧库的该列被忽略，新库不创建。
