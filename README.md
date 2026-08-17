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
- **时间范围切换**：7 / 30 / 90 天

用户详情页（`/admin/user/{tokenName}`、`/usage`、`/user/{apikey}`）同样提供缓存命中率卡片与缓存利用率趋势图，
日志明细、CSV 导出、每小时明细中均含「缓存读取 / 缓存写入」两列。

> 缓存数据来自 `logs.other`（NewAPI 写入的 JSON），口径与计费公式见 [docs/database-schema.md](docs/database-schema.md)。
> 「预估节省」对按次计价的模型不适用，会记为 0。

## 技术栈

- PHP 8.1 + Laravel 8
- Tailwind CSS（CDN）
- Chart.js（CDN）
- DDEV 本地开发环境

## 部署

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
