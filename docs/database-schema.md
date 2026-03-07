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
