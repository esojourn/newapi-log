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
- `routes/web.php` — Web 路由（后台登录与统计仪表盘）
- `resources/views/admin/login.blade.php` — 登录页视图
- `resources/views/admin/dashboard.blade.php` — 统计仪表盘视图（Tailwind CSS + Chart.js）

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

### 数据库

连接外部 MySQL/MariaDB 的 `newapi` 数据库，直接读取已有的 `logs` 和 `tokens` 表。本项目的 `database/migrations/` 中的迁移文件是 Laravel 默认模板，与核心业务无关。
