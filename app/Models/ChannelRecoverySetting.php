<?php

namespace App\Models;

use App\Support\EncryptsAttributes;
use Illuminate\Database\Eloquent\Model;

class ChannelRecoverySetting extends Model
{
    use EncryptsAttributes;

    protected $connection = 'alerts';

    protected $guarded = [];

    protected $hidden = ['access_token'];

    protected $casts = [
        'enabled' => 'boolean',
        'user_id' => 'integer',
        'http_timeout' => 'integer',
    ];

    /** 首次保存前沿用环境配置；保存后整份配置以本地设置为准，包括关闭和空值。 */
    public static function current(): self
    {
        return static::query()->find(1) ?? new static([
            'id' => 1,
            'enabled' => config('channels.recovery_enabled'),
            'schedule_cron' => config('channels.schedule_cron'),
            'base_url' => config('channels.base_url'),
            'access_token' => config('channels.access_token'),
            'user_id' => config('channels.user_id') ?: null,
            'http_timeout' => config('channels.http_timeout'),
        ]);
    }

    public function configuration(): array
    {
        return [
            'recovery_enabled' => $this->enabled,
            'schedule_cron' => $this->schedule_cron,
            'base_url' => $this->base_url,
            'access_token' => $this->access_token,
            'user_id' => $this->user_id,
            'http_timeout' => $this->http_timeout,
        ];
    }

    public function getAccessTokenAttribute($value): ?string
    {
        return $this->decryptAttribute($value);
    }

    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $this->encryptAttribute($value);
    }
}
