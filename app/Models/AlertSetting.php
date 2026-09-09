<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * 管理员的全局预警设置，key-value 单表。
 *
 * 只有四个键（见下方常量）。webhook 两项以密文落盘，理由同 EncryptsAttributes。
 *
 * 读写方法特意叫 getValue / setValue 而不是 get / put：Model 的 __callStatic 会把
 * 未定义的静态调用转给查询构造器，同名静态方法会把 Builder::get() 遮住。
 */
class AlertSetting extends Model
{
    public const ADMIN_ENABLED = 'admin_enabled';
    public const ADMIN_WEBHOOK_URL = 'admin_webhook_url';
    public const ADMIN_WEBHOOK_SECRET = 'admin_webhook_secret';
    public const ADMIN_DEFAULT_THRESHOLD_QUOTA = 'admin_default_threshold_quota';

    /** 这几个键的值加密存储 */
    private const ENCRYPTED_KEYS = [
        self::ADMIN_WEBHOOK_URL,
        self::ADMIN_WEBHOOK_SECRET,
    ];

    protected $connection = 'alerts';

    protected $table = 'alert_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, $default = null)
    {
        $row = static::query()->find($key);

        if ($row === null || $row->value === null || $row->value === '') {
            return $default;
        }

        if (!in_array($key, self::ENCRYPTED_KEYS, true)) {
            return $row->value;
        }

        try {
            return Crypt::decryptString($row->value);
        } catch (DecryptException $e) {
            Log::warning('预警设置解密失败，可能是 APP_KEY 变更过，需要重新填写', ['key' => $key]);

            return $default;
        }
    }

    public static function setValue(string $key, $value): void
    {
        if ($value === null || $value === '') {
            $stored = null;
        } elseif (in_array($key, self::ENCRYPTED_KEYS, true)) {
            $stored = Crypt::encryptString((string) $value);
        } else {
            $stored = (string) $value;
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
    }

    public static function adminEnabled(): bool
    {
        return (bool) static::getValue(self::ADMIN_ENABLED, false);
    }

    public static function adminWebhookUrl(): ?string
    {
        return static::getValue(self::ADMIN_WEBHOOK_URL);
    }

    public static function adminWebhookSecret(): ?string
    {
        return static::getValue(self::ADMIN_WEBHOOK_SECRET);
    }

    /** 管理员的默认阈值（quota），未设置时返回 null */
    public static function adminDefaultThresholdQuota(): ?int
    {
        $value = static::getValue(self::ADMIN_DEFAULT_THRESHOLD_QUOTA);

        return $value === null ? null : (int) $value;
    }
}
