<?php

namespace App\Support;

class UpstreamMessage
{
    /** 只存提示文字；屏蔽已知凭据和常见认证格式，再限制长度。 */
    public static function sanitize($value, array $secrets = []): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $secrets = array_values(array_filter($secrets, fn ($secret) => is_string($secret) && $secret !== ''));
        usort($secrets, fn ($left, $right) => strlen($right) <=> strlen($left));
        foreach ($secrets as $secret) {
            $value = str_replace([$secret, rawurlencode($secret)], '[已隐藏]', $value);
        }

        $value = preg_replace([
            '~\b(?:Bearer|Basic)\s+[^\s,;"\'<>]+~i',
            '~\bsk-[a-z0-9_.*-]+~i',
            '~((?:["\']?)(?:authorization|api[_-]?key|access[_-]?token|token|secret|password|key)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&<>]+)~i',
            '~(https?://)[^\s/@]+:[^\s/@]+@~i',
        ], ['[已隐藏]', '[已隐藏]', '$1[已隐藏]', '$1[已隐藏]@'], $value);
        // 保留换行，去掉不可见控制字符，页面统一按纯文本转义展示。
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));

        return $value === '' ? null : mb_strimwidth($value, 0, 4000, '…', 'UTF-8');
    }
}
