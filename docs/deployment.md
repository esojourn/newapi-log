# 部署说明

面向生产环境的完整部署流程。本地开发用 DDEV，见 [README](../README.md#本地开发ddev)。

## 先读这三条

这个服务在部署上有三个和普通 Laravel 项目不一样的地方，不了解会踩坑：

1. **外部 NewAPI 库是只读的，绝不能裸跑 `php artisan migrate`。**
   默认连接指向生产库，而 `database/migrations/` 根目录下躺着 Laravel 的 4 个模板迁移
   （`create_users_table` 等，从未执行过）。裸跑会往 NewAPI 生产库里建 `users` /
   `password_resets` / `failed_jobs` 三张表。本项目的迁移**永远**要带上连接和路径，
   见[第 4 步](#4-额度预警的本地库)。

2. **本项目自己的数据只有一个 SQLite 文件**（`database/alerts.sqlite`，额度预警的设置与
   订阅）。它需要 PHP 进程可写，且是唯一需要备份的东西。

3. **`APP_KEY` 是加密根。** 飞书 Webhook 地址与签名密钥以密文落盘（APP_KEY 派生）。
   换掉 APP_KEY 等于把所有已保存的 Webhook 作废 —— 服务不会崩，但那些订阅会被跳过，
   并在设置页显示「Webhook 地址无法读取，请重新填写」。备份 SQLite 文件时**务必连
   APP_KEY 一起备份**，否则恢复出来的是一堆解不开的密文。

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | 8.1（`composer.json` 声明 `^7.3\|^8.0`，实际按 8.1 开发与测试） |
| 扩展 | Laravel 8 标配（BCMath、Ctype、cURL、DOM、Fileinfo、JSON、Mbstring、OpenSSL、PCRE、PDO、Tokenizer、XML）外加 **`pdo_mysql`**（外部 NewAPI 库）与 **`pdo_sqlite`**（额度预警的本地库） |
| Composer | 2.x |
| Web 服务器 | document root 指向 `public/`，不是项目根目录 |
| 数据库 | 外部 MySQL / MariaDB 的 `newapi` 库，只需**只读**权限（`SELECT`） |

前端资源全部走 CDN（Tailwind、Chart.js），不需要 Node 或构建步骤。

## 首次部署

### 1. 拉代码、装依赖

```bash
git clone <repo> /path/to/newapi-log
cd /path/to/newapi-log
composer install --no-dev --optimize-autoloader
```

### 2. 生成 APP_KEY 并配置环境变量

```bash
cp .env.example .env
php artisan key:generate
```

`.env` 里必须改的：

```env
APP_ENV=production
APP_DEBUG=false
# 飞书预警卡片上「查看用量」按钮的落地页由它拼出来，
# 留在 http://localhost 的话推过去的按钮点不开
APP_URL=https://your-domain.example

# 外部 NewAPI 库（只读）
DB_HOST=your-db-host
DB_DATABASE=newapi
DB_USERNAME=your-username
DB_PASSWORD=your-password

# 后台密码认证
ADMIN_PASSWORD=your-admin-password
```

可选，但改动时**必须两个一起改**：

```env
APP_TIMEZONE=Asia/Shanghai   # 默认值
DB_TIMEZONE=+08:00           # 默认值，要与 APP_TIMEZONE 指向同一时区
```

统计的桶边界由 PHP 侧的 Carbon 算、分组由 SQL 侧的 `FROM_UNIXTIME()` 做，两边时区不一致
会让桶键字符串对不上，**图表静默变 0 而不报错**。理由见
[docs/database-schema.md](database-schema.md#时区)。

### 3. 目录权限

session 驱动是 `file`，日志也写在本地，所以 PHP 进程（php-fpm / apache 用户）需要
对这两个目录可写：

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 4. 额度预警的本地库

SQLite 文件不会自动创建，Laravel 连不到不存在的文件会直接报错：

```bash
touch database/alerts.sqlite
chown www-data:www-data database/alerts.sqlite database
php artisan migrate --database=alerts --path=database/migrations/alerts
```

`--database` 和 `--path` **两个都不能省**（原因见开头第 1 条）。

`database` 目录本身也要给写权限：SQLite 写入时会在同目录建 `-wal` / `-journal` 临时文件，
只给文件权限、不给目录权限会以 `attempt to write a readonly database` 收场。

### 5. 定时检查

预警靠 Laravel 的调度器驱动，需要系统 crontab 里有这么一条：

```cron
* * * * * cd /path/to/newapi-log && php artisan schedule:run >> /dev/null 2>&1
```

**这条 cron 要用和 PHP-FPM 相同的用户**（上面例子里是 `www-data`）。用 root 跑会让
SQLite 的 `-wal` 文件变成 root 所有，之后网页端再写设置就写不进去了。

调度频率由 `ALERT_CHECK_CRON` 决定（默认 `*/30 * * * *`，即每半小时一轮）。可选项：

```env
ALERT_DB_DATABASE=             # SQLite 路径，留空用 database/alerts.sqlite
ALERT_CHECK_CRON="*/30 * * * *"
ALERT_REMIND_HOURS=24          # 余额持续低于阈值时的复发提醒间隔
ALERT_DEFAULT_THRESHOLD=5      # 设置页表单的默认预警金额（美元）
ALERT_HTTP_TIMEOUT=10          # 调用飞书接口的超时秒数
```

确认调度已注册：

```bash
php artisan schedule:list
```

### 6. 缓存优化（可选）

项目里 `env()` 只在 `config/` 下使用，所以 `config:cache` 是安全的：

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**代价**：缓存之后改 `.env` 不再生效，每次改完都要重跑 `php artisan config:cache`
（或先 `config:clear`）。

## 部署后验证

逐条过一遍，全绿才算部署完：

```bash
# 1. 配置能读到、外部库能连上
php artisan tinker --execute="echo App\Models\Token::count();"

# 2. 预警检查跑得通（--dry-run 只判定，不发送、不写去重记录）
php artisan alerts:check --dry-run
```

浏览器侧：

| 检查 | 期望 |
|---|---|
| `GET /api/log`，带某个 Key 的 `Authorization: Bearer sk-...` | 返回该 Key 的日志分页 JSON |
| 打开 `/admin`，用 `ADMIN_PASSWORD` 登录 | 仪表盘出图，缺省 24 小时窗口有数据 |
| 仪表盘切到 7 / 30 天 | 曲线不为空（为空多半是时区没配对，见排障表） |
| `/admin/alerts` 填好 Webhook 保存后点「发送测试消息」 | 飞书群里收到绿色测试卡片 |
| `/admin/alerts` 点「立即检查」 | 顶部提示「检查完成：检查 N，用户推送 …」 |
| 首页输入 API Key 登录 → 导航栏「通知设置」 | `/usage/alerts` 能打开并保存 |

## 日常更新

```bash
cd /path/to/newapi-log
git pull
composer install --no-dev --optimize-autoloader

# 只有新增了预警相关迁移时才需要，同样不能省参数
php artisan migrate --database=alerts --path=database/migrations/alerts

php artisan config:clear && php artisan cache:clear && php artisan route:clear && php artisan view:clear
# 如果用了第 6 步的缓存优化，这里再 config:cache / route:cache / view:cache 一遍
```

服务是无状态的只读查询 + 一个 SQLite 文件，不需要停机窗口，也没有队列 worker 要重启。

## 备份与回滚

**需要备份的只有两样**，且必须成对备份：

| 对象 | 说明 |
|---|---|
| `database/alerts.sqlite` | 预警设置、监控名单、推送去重记录 |
| `.env` 里的 `APP_KEY` | 解密 Webhook 地址与签名密钥的钥匙，丢了备份等于废掉 |

```bash
# 备份（SQLite 在线备份，避免拷到写了一半的文件）
sqlite3 database/alerts.sqlite ".backup '/backup/alerts-$(date +%F).sqlite'"
```

外部 NewAPI 库本项目只读，回滚代码不涉及任何数据修复；直接 `git checkout` 上一个版本
再走一遍「日常更新」即可。

## 排障

| 症状 | 原因 | 处理 |
|---|---|---|
| 预警一条都不发 | cron 没跑起来 | `php artisan schedule:list` 看有没有 `alerts:check`；手动 `php artisan alerts:check --dry-run` 看统计里 `checked` 是不是 0 |
| 某个 Key 不发 | 开关没开 / 阈值或 Webhook 没填 / 是 `unlimited_quota` 的 Key / 还在复发间隔内 | `php artisan alerts:check --token-id=<id> --dry-run` 单独跑这个 Key |
| 设置页显示「Webhook 地址无法读取，请重新填写」 | `APP_KEY` 换过，密文解不开 | 重新填一次 Webhook 保存；恢复旧 APP_KEY 也可以 |
| 飞书返回签名校验失败 | 「签名校验密钥」填错，或服务器时间偏差过大（签名带时间戳） | 核对密钥；`timedatectl` 校时 |
| 保存 Webhook 时提示只支持飞书地址 | SSRF 白名单拦截，只放行 `open.feishu.cn` / `open.larksuite.com` 的机器人地址 | 用群机器人的 Webhook；白名单在 `config/alerts.php`，放宽前先想清楚后果 |
| 图表空白或逐小时明细与当日汇总对不上 | `APP_TIMEZONE` 与 `DB_TIMEZONE` 不是同一时区 | 两个一起改回一致，然后 `config:clear` |
| 后台反复要求重新登录 | `ADMIN_PASSWORD` 或 `APP_KEY` 变过，30 天长效 cookie 的 HMAC 失效 | 重新登录一次即可 |
| 网页端保存设置报 `readonly database` | SQLite 文件或 `database` 目录对 PHP 进程不可写，常见于 cron 用 root 跑过 | `chown www-data:www-data database database/alerts.sqlite*`，并把 cron 改成同一用户 |
| `/admin` 或 `/api/log` 报 500 | 外部 NewAPI 库连不上 | 查 `storage/logs/laravel.log`；确认 `DB_HOST` 可达、账号有 `SELECT` 权限 |

改完 `.env` 一律记得 `php artisan config:clear`，否则看到的还是旧配置。
