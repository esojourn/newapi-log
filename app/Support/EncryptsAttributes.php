<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * 让模型的某几列以密文落盘。
 *
 * 用的是 Laravel 的 Crypt（APP_KEY 派生），而不是 'encrypted' cast：cast 在
 * APP_KEY 变过之后会直接抛 DecryptException，把整个页面打挂。webhook 丢了顶多
 * 是重新填一次，不值得让读取路径炸掉，所以这里解密失败一律降级成 null 并记日志。
 */
trait EncryptsAttributes
{
    protected function encryptAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }

    protected function decryptAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            Log::warning('预警设置解密失败，可能是 APP_KEY 变更过，需要重新填写', [
                'model' => static::class,
                'id' => $this->getKey(),
            ]);

            return null;
        }
    }
}
