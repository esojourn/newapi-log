<?php

namespace App\Support;

class ChannelLogMessage
{
    public static function groupingKey(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        // NewAPI 会在提示和嵌套 JSON 正文中重复附加请求 ID；只忽略这些标识，
        // 保留状态码、错误正文及其他数字，避免合并不同故障。原始消息不改写。
        $normalized = preg_replace('/[ \t]*\(request[ _-]?id[ \t]*:[ \t]*[a-z0-9._:-]+[ \t]*\)/i', '', $message);

        return hash('sha256', $normalized);
    }
}
