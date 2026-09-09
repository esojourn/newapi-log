<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 管理员的监控名单：勾选哪些 Key 要盯着。
 *
 * 与 AlertSubscription 相互独立——同一个 Key 可以既被用户自己订阅、又被管理员
 * 监控，两边各有各的阈值和各自的推送去重记录，互不影响。
 *
 * webhook 不在这里：管理员共用一个全局 webhook，存在 alert_settings。
 */
class AlertAdminWatch extends Model
{
    protected $connection = 'alerts';

    protected $table = 'alert_admin_watches';

    protected $fillable = [
        'token_id',
        'token_name',
        'threshold_quota',
        'last_notified_at',
        'last_notified_quota',
        'last_error',
        'last_error_at',
    ];

    protected $casts = [
        'token_id' => 'int',
        'threshold_quota' => 'int',
        'last_notified_quota' => 'int',
        'last_notified_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];
}
