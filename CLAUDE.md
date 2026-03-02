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

- `app/Http/Controllers/ApiController.php` — 唯一的业务控制器
- `app/Models/Log.php` — 日志模型（`logs` 表）
- `app/Models/Token.php` — Token 模型（`tokens` 表）
- `routes/api.php` — API 路由定义（`/api/log` 已禁用限流）

### 数据库

连接外部 MySQL/MariaDB 的 `newapi` 数据库，直接读取已有的 `logs` 和 `tokens` 表。本项目的 `database/migrations/` 中的迁移文件是 Laravel 默认模板，与核心业务无关。
