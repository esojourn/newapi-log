# NewAPI Log

基于 Laravel 8 构建的 API 日志查询与用量统计服务。连接外部 NewAPI 数据库，提供 Token 鉴权的日志查询接口和后台统计仪表盘。

## 功能

### API 日志查询

`GET /api/log` — 通过 Token 鉴权，分页查询当前用户的 API 调用日志。

| 参数 | 说明 |
|------|------|
| `Authorization` | Bearer Token（请求头） |
| `page` | 页码，默认 1 |
| `pageSize` | 每页条数，默认 10，上限 1000 |

每条记录返回 `id`、`created_at`、`model_name`、`prompt_tokens`、`cache_tokens`（缓存读取）、
`cache_creation_tokens`（缓存写入）、`completion_tokens`、`quota`。

### 后台统计仪表盘

`/admin` — 密码认证的用量统计面板，包含：

- **总览卡片**：总请求数、总 Token 数、活跃用户数、缓存命中率
- **Top 10 用户排行表格**：请求数、Prompt/Completion/总 Tokens、主要模型
- **柱状图**：Top 10 用户 Token 用量对比
- **环形图**：模型使用分布
- **折线图**：Top 10 用户每日用量趋势
- **缓存利用率趋势**：输入 Tokens 按缓存读取 / 缓存写入 / 未命中拆分的堆叠柱图，叠加命中率曲线与预估节省金额
- **时间范围切换**：24 小时 / 3 / 7 / 30 / 90 天，缺省 24 小时（按整点小时汇总，其余按自然日）

用户详情页（`/admin/user/{tokenName}`、`/usage`、`/user/{apikey}`）同样提供缓存命中率卡片与缓存利用率趋势图，
日志明细、CSV 导出、每小时明细中均含「缓存读取 / 缓存写入」两列。

> 缓存数据来自 `logs.other`（NewAPI 写入的 JSON），口径与计费公式见 [docs/database-schema.md](docs/database-schema.md)。
> 「预估节省」对按次计价的模型不适用，会记为 0。

### 额度预警通知（飞书）

Key 余额低于设定金额时，自动推送飞书消息。分两套互不影响的设置：

| | 谁来配 | 在哪配 | 推给谁 |
|---|---|---|---|
| **用户侧** | Key 的持有者 | `/usage/alerts`（首页输入 API Key 登录后，导航栏「通知设置」） | 用户自己的飞书群机器人 |
| **管理员侧** | 管理员 | `/admin/alerts`（仪表盘导航栏「预警通知」） | 管理员自己的飞书群机器人 |

管理员在 `/admin/alerts` 里搜索、勾选要监控的 Key，可以给每个 Key 单独设阈值，
留空则用全局默认金额。同一个 Key 可以同时被用户和管理员监控，两边阈值和推送记录各算各的。

推送节奏：余额低于阈值时立即推一条，之后持续低于阈值则每 `ALERT_REMIND_HOURS`（默认 24）
小时提醒一次；余额回到阈值以上会清空推送记录，下次跌破时重新立即提醒。
`unlimited_quota` 的 Key 没有可比较的剩余额度，不做余额预警。

> 设置数据存在**独立的 SQLite 文件**（`database/alerts.sqlite`）里，外部 NewAPI 库保持只读。
> Webhook 地址与签名密钥以密文落盘（APP_KEY 派生）。

#### 部署

> 生产环境的完整步骤（目录权限、cron 用户、备份）见 [docs/deployment.md](docs/deployment.md)。

1. 建库并跑迁移（**必须带上 `--database` 和 `--path`**，否则会往外部 NewAPI 库里建 Laravel 的模板表）：

```bash
touch database/alerts.sqlite
php artisan migrate --database=alerts --path=database/migrations/alerts
```

2. 加一条 crontab，让 Laravel 的调度器跑起来：

```cron
* * * * * cd /path/to/newapi-log && php artisan schedule:run >> /dev/null 2>&1
```

3. 可选的环境变量（改完记得 `php artisan config:clear`）：

```env
ALERT_DB_DATABASE=          # SQLite 文件路径，留空用 database/alerts.sqlite
ALERT_CHECK_CRON="*/30 * * * *"
ALERT_REMIND_HOURS=24
ALERT_DEFAULT_THRESHOLD=5
```

手动跑一次检查：

```bash
php artisan alerts:check --dry-run     # 只判定不发送
php artisan alerts:check               # 真实推送
php artisan alerts:check --token-id=7  # 只检查某个 Key，排障用
```

#### 飞书机器人怎么拿

在飞书群里「设置 → 群机器人 → 添加机器人 → 自定义机器人」，复制 Webhook 地址。
安全设置二选一：

- **签名校验** —— 把飞书给的密钥填进设置页的「签名校验密钥」。
- **关键词** —— 关键词填 `余额预警`，设置页的密钥留空。

出于 SSRF 防护，只接受 `open.feishu.cn` / `open.larksuite.com` 的机器人地址
（白名单见 `config/alerts.php`）。

## 技术栈

- PHP 8.1 + Laravel 8
- Tailwind CSS（CDN）
- Chart.js（CDN）
- DDEV 本地开发环境

## 部署

完整的生产部署流程（权限、cron、备份、排障表）见 [docs/deployment.md](docs/deployment.md)。
下面是最小步骤：

1. 克隆项目并安装依赖：

```bash
composer install
```

2. 复制并配置环境变量：

```bash
cp .env.example .env
php artisan key:generate
```

3. 在 `.env` 中配置数据库连接（指向 NewAPI 数据库）和后台密码：

```env
DB_HOST=your-db-host
DB_DATABASE=newapi
DB_USERNAME=your-username
DB_PASSWORD=your-password

ADMIN_PASSWORD=your-admin-password
```

4. 启动服务即可使用。

## 更新配置

修改 `.env`（如数据库连接信息）后，需清除 Laravel 配置缓存使其生效，无需重启 Apache：

```bash
php artisan config:clear
php artisan cache:clear
```

## 本地开发（DDEV）

```bash
ddev start
ddev composer install
```

访问 `https://api-log.ddev.site/admin` 进入后台。
