# 数据库结构文档

本文档记录 NewAPI 数据库的表结构，供开发参考。

## tokens 表

| 字段 | 类型 | 约束 | 说明 |
|------|------|------|------|
| `id` | int | PK, 自增 | Token 唯一标识 |
| `user_id` | int | FK, 索引 | 所属用户 ID |
| `key` | string | char(48), 唯一索引 | API 密钥（格式：`sk-...`） |
| `status` | int | 默认: 1 | Token 状态：1=启用, 2=耗尽, 3=过期 |
| `name` | string | 索引 | 用户定义的 Token 名称 |
| `created_time` | int64 | bigint | 创建时间（Unix 时间戳） |
| `accessed_time` | int64 | bigint | 最后访问时间（Unix 时间戳） |
| `expired_time` | int64 | bigint, 默认: -1 | 过期时间，-1=永不过期 |
| `remain_quota` | int | 默认: 0 | **剩余配额** |
| `unlimited_quota` | bool | - | 是否无限配额 |
| `model_limits_enabled` | bool | - | 是否启用模型过滤 |
| `model_limits` | string | varchar(1024), 默认: '' | 允许的模型列表（逗号分隔） |
| `allow_ips` | string | 默认: '' | IP 白名单（换行分隔） |
| `used_quota` | int | 默认: 0 | **已使用配额** |
| `group` | string | 默认: '' | 用户组覆盖 |
| `deleted_at` | timestamp | 索引 | 软删除时间戳 |

### 配额相关字段

- `remain_quota`: 可用配额余额
- `used_quota`: 累计消费追踪
- `unlimited_quota`: 布尔标志，绕过配额检查

### 配额转换公式

```
金额 = quota / 500000
```

## logs 表

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 日志 ID |
| `created_at` | int64 | 创建时间（Unix 时间戳） |
| `token_name` | string | Token 名称 |
| `model_name` | string | 模型名称 |
| `prompt_tokens` | int | 输入 Token 数 |
| `completion_tokens` | int | 输出 Token 数 |
| `quota` | int | 本次消费配额 |
| `group` | string | 分组 |
| `use_time` | int | 使用时长 |
| `is_stream` | bool | 是否流式 |
| `content` | text | 内容 |
| `other` | text | 附加信息（JSON 字符串），见下 |

### other 列

NewAPI 把计费明细与缓存用量塞在 `other` 里，由上游 `service/log_info_generate.go`
的 `GenerateTextOtherInfo` / `GenerateClaudeOtherInfo` 写入。本项目的缓存统计是唯一的数据来源。

| key | 说明 | 缺失时按 |
|------|------|------|
| `cache_tokens` | 命中缓存、被读取的输入 Token | 0 |
| `cache_ratio` | 命中 Token 的计费折扣率（如 0.1 = 一折） | 1（无折扣） |
| `cache_creation_tokens` | 写入缓存的 Token（Claude） | 0 |
| `cache_creation_ratio` | 写入 Token 的计费倍率（通常 1.25） | 1 |
| `model_ratio` / `group_ratio` | 模型与分组计费倍率 | 0 / 1 |
| `model_price` | 大于 0 表示按次计价，此时倍率不参与计费 | 0 |
| `admin_info` | 渠道等内部信息，**不应对外暴露** | - |

**重要语义**：`prompt_tokens` 已经**包含** `cache_tokens` 与 `cache_creation_tokens`。
按量计费公式为：

```
quota = (未命中输入 + 命中×cache_ratio + 写入×cache_creation_ratio + 输出×completion_ratio)
        × model_ratio × group_ratio
```

由此可得本项目使用的两个派生指标：

```
未命中输入 = MAX(0, prompt_tokens - cache_tokens - cache_creation_tokens)
缓存命中率 = cache_tokens / (cache_tokens + cache_creation_tokens + 未命中输入)
预估节省   = cache_tokens × (1 - cache_ratio) × model_ratio × group_ratio   // model_price > 0 时记 0
```

**读取注意**：`other` 可能是 NULL、空串或非法 JSON（老日志、充值等非消费类日志）。
MySQL 8 对空串调用 `JSON_EXTRACT` 会直接抛 `ERROR 3141`，因此 SQL 中必须先用
`JSON_VALID` 守卫（MariaDB 只会返回 NULL，但两边都要兼容）。
