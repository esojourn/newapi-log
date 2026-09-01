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
| key | 说明 | 缺失时按 |
|------|------|------|
| `usage_semantic` | `anthropic` 表示 Claude 计费语义，**决定 `prompt_tokens` 是否含缓存** | 空（OpenAI 语义） |
| `cache_tokens` | 命中缓存、被读取的输入 Token | 0 |
| `cache_ratio` | 命中 Token 的计费折扣率（如 0.1 = 一折） | 1（无折扣） |
| `cache_creation_tokens` | 写入缓存的 Token；**有分档时只记 5 分钟那一档** | 0 |
| `cache_creation_ratio` | 写入 Token 的计费倍率（通常 1.25） | 1 |
| `cache_creation_tokens_5m` / `_1h` | 5 分钟 / 1 小时缓存写入的分档用量 | 0 |
| `cache_creation_ratio_5m` / `_1h` | 对应倍率，1 小时通常是 **2.0**，5 分钟 1.25 | 1.25 / 2 |
| `cache_write_tokens` | 上游算好的缓存写入总量，等于 `MAX(合并字段, 5m + 1h)` | 同合并字段 |
| `image_output` / `image_ratio` | 图像 Token 及其倍率，从输入里单独拆出计价 | 0 / 1 |
| `completion_ratio` | 输出 Token 的计费倍率 | 1 |
| `model_ratio` / `group_ratio` | 模型与分组计费倍率 | 0 / 1 |
| `model_price` | 大于 0 表示按次计价，此时倍率不参与计费 | 0 |
| `admin_info` | 渠道等内部信息，**不应对外暴露** | - |

**重要语义**：`prompt_tokens` 是否包含缓存 Token **取决于 `usage_semantic`**，
不是固定的。对应上游 `service/text_quota.go` 里只在非 Anthropic 语义时才
`baseTokens.Sub(dCacheTokens)`：

| 判别条件 | `prompt_tokens` |
|------|------|
| `usage_semantic = 'anthropic'` | **不含**缓存，本身就是未命中的新输入 |
| 语义缺失但有 `_5m`/`_1h` 分档（`isLegacyClaudeDerivedOpenAIUsage`） | **不含**缓存 |
| 其余（OpenAI 语义） | **包含**缓存，需相减 |

实测本库 18.2 万条日志中，`usage_semantic` 只有 `anthropic`（16.2 万条）与缺失
（2.0 万条）两种取值。在含缓存的 10.4 万条按量计费日志里，`anthropic` 语义有
99.7% 只在「不含缓存」的口径下才与 `quota` 吻合，语义缺失的则 0% 吻合、
70.6% 匹配「包含」口径。早期文档统一按「包含」处理，导致 Anthropic 日志的
未命中输入被 `MAX(0, ...)` 夹成 0、命中率虚高。

按量计费公式（`model_price <= 0`）：

```
未命中输入 = MAX(0, prompt_tokens - image_output
                 - (prompt 含缓存时 ? cache_tokens + cache_creation_tokens : 0))
缓存写入计费 = 有分档 ? (合并-5m-1h)×ratio + 5m×ratio_5m + 1h×ratio_1h
                      : cache_creation_tokens × cache_creation_ratio

quota = (未命中输入 + 命中×cache_ratio + 图像×image_ratio + 缓存写入计费
         + 输出×completion_ratio) × model_ratio × group_ratio
```

由此可得本项目使用的派生指标：

```
缓存写入总量 = COALESCE(cache_write_tokens, MAX(合并字段, 5m + 1h))
总输入      = 未命中输入 + cache_tokens + 缓存写入总量
缓存命中率   = cache_tokens / 总输入
预估节省     = cache_tokens × (1 - cache_ratio) × model_ratio × group_ratio   // model_price > 0 时记 0
```

**注意**：`SUM(prompt_tokens + completion_tokens)` 在 Anthropic 语义下会漏掉全部
缓存量（实测单用户低估约 785%），统计 Token 总量必须用上面的「总输入 + 输出」。

**遗留数据**：约 1.4 万条早期日志的 `quota` 未乘 `group_ratio`，另有 757 条
（占总 quota 0.017%）用任何公式都对不上，属上游历史写入问题，不影响现网计费。

**读取注意**：`other` 可能是 NULL、空串或非法 JSON（老日志、充值等非消费类日志）。
MySQL 8 对空串调用 `JSON_EXTRACT` 会直接抛 `ERROR 3141`，因此 SQL 中必须先用
`JSON_VALID` 守卫（MariaDB 只会返回 NULL，但两边都要兼容）。

**性能注意**：`other` 是 longtext，`JSON_VALID` 与 `JSON_EXTRACT` 在 18 万行上
各约 1s，成本随调用次数线性增长。组合表达式要在最外层套**一次**守卫、每个 key
只抽**一次**（见 `StatsController::otherIntRaw()` 与 `OTHER_GUARD`），
不要给每个字段各套一遍守卫。多路径 `JSON_EXTRACT(other, '$.a', '$.b')` 虽然只解析
一次，但缺失的 key 会被静默跳过而非留空位，位置不可靠，**不能**用来按下标取值。

## 时区

`logs.created_at` 是 Unix 时间戳。统计里 PHP 侧用 Carbon 算桶边界、SQL 侧用
`DATE(FROM_UNIXTIME(created_at))`（1 天范围下是
`DATE_FORMAT(FROM_UNIXTIME(created_at), '%m-%d %H:00')`）分组，两边时区必须一致，
否则桶边界错位、且两侧桶键字符串对不上会让图表静默变 0：
原先 `config/app.php` 是 UTC 而数据库会话是 `SYSTEM`（+08:00），逐小时明细与
当日汇总能差出 8 小时的量。现在 `app.timezone` 与 `database.connections.mysql.timezone`
都固定为 +08:00（`APP_TIMEZONE` / `DB_TIMEZONE` 可覆盖），改动时必须同步。
