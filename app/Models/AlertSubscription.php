<?php

namespace App\Models;

use App\Support\EncryptsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 用户侧的余额预警订阅：一个 Key（tokens.id）一行，用户自己填飞书 webhook 与阈值。
 *
 * 存在本地 alerts 库（SQLite），不碰外部 newapi 库。
 */
class AlertSubscription extends Model
{
    use EncryptsAttributes;

    protected $connection = 'alerts';

    protected $table = 'alert_subscriptions';

    protected $fillable = [
        'token_id',
        'token_name',
        'enabled',
        'threshold_quota',
        'webhook_url',
        'webhook_secret',
        'last_notified_at',
        'last_notified_quota',
        'last_error',
        'last_error_at',
    ];

    protected $casts = [
        'token_id' => 'int',
        'enabled' => 'bool',
        'threshold_quota' => 'int',
        'last_notified_quota' => 'int',
        'last_notified_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function getWebhookUrlAttribute($value): ?string
    {
        return $this->decryptAttribute($value);
    }

    public function setWebhookUrlAttribute($value): void
    {
        $this->attributes['webhook_url'] = $this->encryptAttribute($value);
    }

    public function getWebhookSecretAttribute($value): ?string
    {
        return $this->decryptAttribute($value);
    }

    public function setWebhookSecretAttribute($value): void
    {
        $this->attributes['webhook_secret'] = $this->encryptAttribute($value);
    }

    /** 配置齐了才有推送的可能：开关、阈值、webhook 三者缺一不可 */
    public function isActionable(): bool
    {
        return $this->enabled
            && $this->threshold_quota !== null
            && $this->webhook_url !== null;
    }
}
