# 渠道自动恢复

本项目定时检测 NewAPI 后台渠道列表中的故障渠道。必须同时满足两个条件：

| NewAPI 字段 | 条件 |
|---|---|
| `auto_ban`（自动封禁） | `1`，已开启 |
| `status`（状态） | `3`，自动禁用 |

启用中的渠道、手动禁用的渠道，以及关闭自动封禁的渠道都不会被测试或启用。
本项目不新增 `/channels` 页面；查看和编辑渠道仍在 NewAPI 后台进行。

## 配置

在 `.env` 中添加：

```env
CHANNEL_RECOVERY_ENABLED=true
CHANNEL_RECOVERY_CRON="*/5 * * * *"
CHANNEL_RECOVERY_HTTP_TIMEOUT=30
NEW_API_BASE_URL=https://your-newapi.example
NEW_API_ACCESS_TOKEN=your-admin-access-token
NEW_API_USER_ID=1
```

- 默认关闭，配置完成后开启；默认每 5 分钟一轮，修改 cron 表达式可调整间隔。
- `NEW_API_BASE_URL` 必须与 `DB_*` 指向**同一个 NewAPI 实例**。填站点地址，不加 `/v1` 或 `/api/channel`；支持站点部署路径。
- `NEW_API_ACCESS_TOKEN` 使用 NewAPI 管理员账号的个人访问令牌，`NEW_API_USER_ID` 为该账号的数字 ID。模型调用用的 `sk-...` 令牌不能替代管理员访问令牌。
- 管理账号需要读取、测试和更新渠道状态的权限。启用细粒度权限的新版需要 `channel.read` / `channel.operate`；传统更新接口还需要渠道编辑权限。
- 外部数据库账号仍然只需要 `SELECT` 权限，需能读取 `channels.id`、`channels.status`、`channels.auto_ban`。**无需新增表或运行迁移。**

清除配置缓存后，先做一次试运行：

```bash
php artisan config:clear
php artisan channels:recover --dry-run
php artisan schedule:list
```

`--dry-run` 会实际测试渠道，但不会启用。测试可能消耗上游额度，NewAPI 也可能更新测试时间、响应耗时并记录测试日志。
检查通过后可手动恢复一轮：

```bash
php artisan channels:recover
# 只处理指定渠道，仍须满足 auto_ban=1 且 status=3
php artisan channels:recover --channel-id=7
php artisan channels:recover --channel-id=7 --dry-run
```

服务器需配置 Laravel 调度器（与额度预警共用，已有则无需重复添加）：

```cron
* * * * * cd /path/to/newapi-log && php artisan schedule:run >> /dev/null 2>&1
```

cron 使用与 PHP-FPM 相同的用户。默认文件缓存支持防重入锁；多实例部署时需共享支持锁的缓存，避免不同机器各自检查。
关闭功能时设置 `CHANNEL_RECOVERY_ENABLED=false`，然后 `php artisan config:clear`；正在执行的一轮会继续完成。

## 检查与恢复流程

1. 从只读数据库按 ID 分批取出符合条件的渠道，读取管理接口再次确认状态。
2. 调用 `GET /api/channel/test/{id}`。由 NewAPI 使用渠道自身的测试模型及协议适配器发起真实请求；只接受 HTTP 成功且 JSON 中 `success` 严格为 `true` 的结果。
3. 恢复前重新读取渠道。检测期间若已手动禁用、已启用或关闭自动封禁，则跳过状态更新。
4. 调用 NewAPI 的状态更新接口恢复到 `status=1`，让 NewAPI 更新路由能力和缓存；随后再次读取，确认已启用才计入“已恢复”。本项目不直接写外部数据库。

新版优先使用 `POST /api/channel/{id}/status`，只发送 `status`。
若该接口明确返回 HTTP 404/405，则重新核对渠道条件，并使用传统的 `PUT /api/channel/`，只发送 `id` 和 `status`。
鉴权失败、业务失败和超时不会触发降级或重复写入。
接口依据：[官方渠道管理文档](https://doc.newapi.pro/api/fei-channel-management/)、[新版渠道路由源码](https://github.com/QuantumNous/new-api/blob/main/router/channel-router.go)。

测试失败或请求异常时不启用该渠道，继续处理其他渠道，下个周期重新检查。
不支持 NewAPI 单渠道测试的渠道类型会保留原状态；多密钥渠道整体仍须是 `status=3`，不会扫描或解封单个密钥。
“恢复正常”以该渠道的测试模型成功为准，并不代表渠道配置中的每个模型都已恢复。

任务使用锁避免定时与手动执行重叠，上一轮未完成时不会启动重复检查。
上游状态更新接口不支持原子条件更新，最后一次读取与更新之间仍有并发窗口；进行人工批量维护时可先停用定时恢复并等待当前轮结束。

## 检查结果

命令输出检查数、测试正常数、已恢复数、因状态变化跳过数和失败数；有失败时返回非零退出码。
`storage/logs/laravel.log` 记录每次成功恢复的渠道 ID、失败阶段及每轮汇总，不记录令牌、渠道密钥或原始接口响应。

| 情况 | 排查 |
|---|---|
| 没有定时检查 | 确认开关为 `true`、配置缓存已刷新、系统 cron 存在；用 `schedule:list` 检查注册结果 |
| 检查数为 0 | 确认渠道同时满足 `auto_ban=1`、`status=3`；手动禁用不会检查 |
| 读取接口失败 | 检查 NewAPI 地址、管理员访问令牌、用户 ID 和读取权限 |
| 测试失败 | 在 NewAPI 后台用同一测试模型测试，检查上游状态、额度和模型配置；必要时调整超时 |
| 测试正常但未恢复 | 查看是否因状态变化跳过；检查状态更新权限、接口版本，以及更新后的实际状态 |
| 提示已有检查运行 | 等当前轮完成；异常退出留下的锁最多保留 24 小时，仅在确认没有任务运行后清理应用缓存 |
