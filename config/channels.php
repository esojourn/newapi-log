<?php

return [
    // 管理员页面首次保存前的默认值；保存后使用本地 channel_recovery_settings。
    // 外部数据库连接仍然只需 SELECT 权限。
    'recovery_enabled' => (bool) env('CHANNEL_RECOVERY_ENABLED', false),
    'schedule_cron' => env('CHANNEL_RECOVERY_CRON', '*/5 * * * *'),

    'base_url' => env('NEW_API_BASE_URL', ''),
    'access_token' => env('NEW_API_ACCESS_TOKEN', ''),
    'user_id' => env('NEW_API_USER_ID', ''),
    'http_timeout' => (int) env('CHANNEL_RECOVERY_HTTP_TIMEOUT', 30),
];
