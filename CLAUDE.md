# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

API 日志查询服务，基于 Laravel 8 构建。通过 Token 鉴权，提供分页查询 API 调用日志的接口。连接的是外部 "newapi" 数据库中已有的 `logs` 和 `tokens` 表（非本项目迁移创建）。

## Development Environment

使用 DDEV 进行本地开发：
- PHP 8.1, nginx-fpm, MariaDB 10.4
- 启动：`ddev start`
- 停止：`ddev stop`
- 进入容器：`ddev ssh`
- 项目地址：`https://api-log.ddev.site`

## Common Commands

```bash
# 依赖安装
ddev composer install

# 运行测试
ddev exec php artisan test
# 或
ddev exec ./vendor/bin/phpunit

# 运行单个测试文件
ddev exec ./vendor/bin/phpunit tests/Feature/ExampleTest.php

# 运行单个测试方法
ddev exec ./vendor/bin/phpunit --filter=testMethodName

# 额度预警的本地库迁移（必须指定连接和路径，别裸跑 migrate）
ddev exec php artisan migrate --database=alerts --path=database/migrations/alerts

# 清除缓存
ddev exec php artisan cache:clear
ddev exec php artisan config:clear
ddev exec php artisan route:clear
```

## Architecture

这是一个极简的只读 API 服务，没有用户注册/登录、没有数据库迁移管理（表由外部系统维护）。

### 核心流程

`GET /api/log` → `ApiController::getLogs`：
1. 从 `Authorization` 头提取 token（去掉 `Bearer ` 前缀后再 `substr($token, 3)` 截取）
2. 在 `tokens` 表中查找匹配的 key
3. 用 token 对应的 `name` 字段过滤 `logs` 表中的 `token_name`
4. 返回分页结果（支持 `page` 和 `pageSize` 查询参数，pageSize 上限 1000）

### 关键文件

- `app/Http/Controllers/ApiController.php` — API 日志查询控制器
- `app/Http/Controllers/AdminController.php` — 后台登录/登出控制器
- `app/Http/Controllers/StatsController.php` — 统计仪表盘控制器
- `app/Http/Middleware/AdminAuth.php` — 后台密码认证中间件
- `app/Models/Log.php` — 日志模型（`logs` 表）
- `app/Models/Token.php` — Token 模型（`tokens` 表）
- `routes/api.php` — API 路由定义（`/api/log` 已禁用限流）
- `routes/web.php` — Web 路由（后台登录、统计仪表盘、额度预警设置）
- `resources/views/admin/login.blade.php` — 登录页视图
- `resources/views/admin/dashboard.blade.php` — 统计仪表盘视图（Tailwind CSS + Chart.js）
- `app/Http/Controllers/AlertController.php` — 额度预警设置（用户侧 + 管理员侧）
- `app/Services/AlertChecker.php` — 预警判定与推送
- `app/Services/FeishuNotifier.php` — 飞书自定义机器人推送与加签
- `app/Console/Commands/CheckBalanceAlerts.php` — `alerts:check` 命令
- `app/Support/Quota.php` — quota ↔ 美元换算（`PER_DOLLAR = 500000`）

### 后台统计

- 路由：`/admin`（需认证）、`/admin/login`、`/admin/logout`
- 认证方式：简单密码认证，密码通过 `ADMIN_PASSWORD` 环境变量配置；
  登录后除 session 外还下发 30 天长效 cookie（`AdminAuth::REMEMBER_COOKIE`，值是
  `ADMIN_PASSWORD` + `APP_KEY` 的 HMAC），session 过期时由 `AdminAuth` 免密续期
- 仪表盘功能：Top 10 用户用量排行、模型使用分布、每日用量趋势、缓存利用率趋势
- 支持 1/3/7/30/90 天时间范围切换，**缺省 1 天**（窗口最小、装载最快）；**1 天（UI 显示「24小时」）走小时粒度** ——
  最近 24 个整点桶，其余按自然日。桶边界、桶键与 SQL 分组表达式统一由
  `StatsController::resolveRange()` 给出，四个统计入口共用
- 时间范围要跨页保留：仪表盘 → 用户详情、用户详情 → 仪表盘的链接都要带上 `days`

### 缓存统计

缓存用量（命中率、缓存读取/写入 Tokens、预估节省金额）来自 `logs.other` —— NewAPI 写入的
JSON 字符串列，**没有独立的缓存字段列**。字段含义、计费公式、时区约束与读取时的
`JSON_VALID` 守卫见 `docs/database-schema.md`。

**`prompt_tokens` 是否包含缓存 Token 取决于 `other.usage_semantic`**，不是固定的：
Anthropic 语义下**不含**，OpenAI 语义下**包含**。判定逻辑在
`StatsController::promptExcludesCacheExpr()`（SQL 侧）与 `Log::cacheTokens()`（PHP 侧），
两处口径必须一致。同理，`SUM(prompt_tokens + completion_tokens)` 会漏掉缓存量，
统计 Token 总量要用 `totalTokensExpr()`。

上游 Go 源码在 `/var/www/html/newapi-vendor`，计费真相在
`service/text_quota.go` 的 `calculateTextQuotaSummary()`；改计费口径前先读它，
不要靠数据反推。上游只存 `quota`（已扣费金额），不存"缓存节省了多少"，
所以预估节省只能本地算。

- SQL 侧的取值表达式集中在 `StatsController` 的 `otherInt()` / `otherIntRaw()` /
  `otherRatio()` / `uncachedPromptExpr()` / `cacheCreationTotalExpr()` /
  `totalInputTokensExpr()` / `cacheSavedQuotaExpr()`，聚合一律合并进现有查询的
  `selectRaw`，不额外扫表
- `other` 是 longtext，每次 JSON 函数调用在 18 万行上约 1s：组合表达式只在最外层套
  一次 `OTHER_GUARD`、每个 key 只抽一次，能从已有聚合推导的值（如 `total_tokens`
  = 三段缓存之和 + 输出）就在 PHP 侧算，别再发一条查询
- 逐行明细（日志列表、CSV 导出、`/api/log`）走 PHP 侧的 `Log::cacheTokens()` 解析，不用 SQL JSON 函数
- `userDetail()` / `usage()` / `publicUserDetail()` 是三份几乎相同的副本，新增用户维度统计时
  用 `applyCacheStats()` 这类共用私有方法，避免再复制三遍

### 额度预警通知

Key 余额低于阈值时推送飞书。用户在 `/usage/alerts` 管自己那个 Key 的一套（session 认证），
管理员在 `/admin/alerts` 勾选监控名单并配另一套阈值，推到管理员自己的飞书。
两套设置、两套推送去重记录完全独立，同一个 Key 可以两边同时监控。

判定与推送在 `AlertChecker::run()`，由 `alerts:check` 命令（`Console\Kernel` 的 schedule）驱动，
后台「立即检查」按钮走同一份代码。

生产部署的完整流程见 `docs/deployment.md`。四条不能从代码直接看出来的约束：

1. **迁移必须指定连接和路径**：

   ```bash
   php artisan migrate --database=alerts --path=database/migrations/alerts
   ```

   `database/migrations/` 根目录下躺着 Laravel 的 4 个模板迁移（`create_users_table` 等），
   从未执行过；默认连接指向外部 newapi 生产库。裸跑 `php artisan migrate` 会往那个库里
   建 `users` / `password_resets` / `failed_jobs` 表。

2. **`config/alerts.php` 的 `webhook_prefixes` 是 SSRF 防线**，不是格式校验。webhook 地址
   由用户自由提交、由服务端发起请求，白名单在表单校验（`AlertController::webhookRules()`）
   和真正发请求前（`FeishuNotifier::send()`）各挡一次。放宽它之前先想清楚后果。

3. **用户侧的 `token_id` 只能从 session 反查**（`AlertController::sessionToken()`，逻辑与
   `StatsController::usage()` 一致），绝不接受表单传入——否则任何登录用户都能改别人 Key 的
   通知设置。公开路径 `/user/{apikey}` 不提供设置入口，那条路径把 key 暴露在 URL 里。

4. **去重规则**（`AlertChecker::decide()`）：余额 ≥ 阈值就清空推送记录，让下次跌破时能立即
   提醒；余额 < 阈值且从未推送过则立即推送；否则按 `alerts.remind_hours`（默认 24 小时）复发。
   保存设置时也会清空推送记录，让新配置立刻生效。`unlimited_quota` 的 Key 一律跳过。

余额口径只用 `tokens.remain_quota`，不读 `users` 表。飞书加签是
「以 `"{timestamp}\n{secret}"` 为**密钥**、对**空串**取 HMAC-SHA256 再 base64」，
key 和 data 写反了永远验不过（`AlertsTest::test_sign_matches_feishu_algorithm` 钉住了这个方向）。

测试写在 `tests/Feature/AlertsTest.php`，跑在内存 SQLite 上（`phpunit.xml` 的 `ALERT_DB_DATABASE`）。
**任何测试都不能用 `RefreshDatabase`** —— 默认连接是外部生产库。tokens 数据靠继承
`AlertChecker` 覆盖 `loadTokens()` 注入。

### 数据库

连接外部 MySQL/MariaDB 的 `newapi` 数据库，直接读取已有的 `logs` 和 `tokens` 表（**只读**）。
`database/migrations/` 根目录下的迁移文件是 Laravel 默认模板，从未执行过，与核心业务无关——
**不要裸跑 `php artisan migrate`**，那会往外部库里建表。

本项目自己的数据（额度预警的设置与订阅）走独立的 `alerts` 连接，是一个 SQLite 文件
（`database/alerts.sqlite`，`ALERT_DB_DATABASE` 可覆盖），迁移在 `database/migrations/alerts/`。
