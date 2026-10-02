<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 最近一轮实际执行的检查；读取渠道失败等不产生监控日志的错误也能在管理员页看到。 */
class ChannelRecoveryRun extends Model
{
    public const MAX_FAILURES = 20;

    protected $connection = 'alerts';

    protected $guarded = [];

    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'checked' => 'integer',
        'healthy' => 'integer',
        'recovered' => 'integer',
        'skipped' => 'integer',
        'failed' => 'integer',
        'failures' => 'array',
    ];

    public static function current(): ?self
    {
        return static::query()->find(1);
    }

    public function hasProblems(): bool
    {
        return $this->error !== null || $this->failed > 0;
    }
}
