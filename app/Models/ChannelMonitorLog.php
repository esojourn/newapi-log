<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelMonitorLog extends Model
{
    public const RESULTS = [
        'pending' => '待检测 / 未完成',
        'healthy' => '测试正常',
        'failed' => '测试失败',
        'error' => '检测异常',
    ];

    protected $connection = 'alerts';

    protected $guarded = [];

    protected $casts = [
        'channel_id' => 'integer',
        'dry_run' => 'boolean',
        'disabled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
