<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelRecoveryLog extends Model
{
    public const RESULTS = [
        'recovered' => '已恢复',
        'skipped' => '已跳过',
        'failed' => '恢复未确认',
        'pending' => '处理中 / 未完成',
    ];

    protected $connection = 'alerts';

    protected $guarded = [];

    protected $casts = [
        'channel_id' => 'integer',
        'from_status' => 'integer',
        'target_status' => 'integer',
        'completed_at' => 'datetime',
    ];
}
