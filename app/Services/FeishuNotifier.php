<?php

namespace App\Services;

use App\Support\Quota;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 飞书自定义机器人推送。
 *
 * 用的是群机器人的 webhook（open.feishu.cn/open-apis/bot/v2/hook/...），不涉及
 * 自建应用与 tenant_access_token，所以无状态、无需缓存凭证。
 */
class FeishuNotifier
{
    /**
     * 发送一条消息。
     *
     * @param  array  $message  完整消息体（含 msg_type / card），签名字段由本方法补
     * @return array{ok: bool, error: ?string}
     */
    public function send(string $webhookUrl, ?string $secret, array $message): array
    {
        // 白名单在表单校验时已经挡过一次，这里是发请求前的最后一道：
        // 任何绕过表单写进库的地址（手改 sqlite、旧数据）都不会被真的请求出去
        if (!self::isAllowedWebhook($webhookUrl)) {
            return ['ok' => false, 'error' => 'Webhook 地址不在允许的飞书域名白名单内'];
        }

        $payload = $message;

        // 机器人开了「签名校验」时才需要，与 msg_type 平级放在顶层
        if ($secret !== null && $secret !== '') {
            $timestamp = (string) time();
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = self::sign($timestamp, $secret);
        }

        try {
            $response = Http::timeout(config('alerts.http_timeout'))->post($webhookUrl, $payload);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => '请求飞书失败：' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'error' => '飞书返回 HTTP ' . $response->status()];
        }

        $body = $response->json();

        if (!is_array($body)) {
            return ['ok' => false, 'error' => '飞书返回了无法解析的响应'];
        }

        // 机器人接口有两种返回格式：新版 {code,msg}，老版 {StatusCode,StatusMessage}
        $code = $body['code'] ?? $body['StatusCode'] ?? null;

        if ($code === 0 || $code === '0') {
            return ['ok' => true, 'error' => null];
        }

        $reason = $body['msg'] ?? $body['StatusMessage'] ?? '未知错误';

        return ['ok' => false, 'error' => "飞书返回 code={$code}：{$reason}"];
    }

    /**
     * 只允许飞书/Lark 官方机器人 webhook。
     *
     * webhook 地址来自用户输入而请求由服务端发起，不限制就等于开了一个 SSRF 口子。
     * 白名单前缀见 config/alerts.php。
     */
    public static function isAllowedWebhook(?string $url): bool
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        foreach ((array) config('alerts.webhook_prefixes') as $prefix) {
            if (strncmp($url, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 飞书加签：以 "{timestamp}\n{secret}" 为 **密钥**，对**空字符串**做 HMAC-SHA256。
     * key 与 data 的位置反过来就永远验不过，改这里前先看飞书文档。
     */
    public static function sign(string $timestamp, string $secret): string
    {
        $stringToSign = $timestamp . "\n" . $secret;

        return base64_encode(hash_hmac('sha256', '', $stringToSign, true));
    }

    /** 单个 Key 的余额预警卡片（用户侧） */
    public function balanceCard(string $tokenName, int $remainQuota, int $thresholdQuota, ?string $linkUrl = null): array
    {
        $elements = [
            [
                'tag' => 'div',
                'fields' => [
                    self::field('API Key', self::plain($tokenName)),
                    self::field('当前余额', "<font color='red'>" . Quota::format($remainQuota) . '</font>'),
                    self::field('预警阈值', Quota::format($thresholdQuota)),
                    self::field('触发时间', Carbon::now()->format('Y-m-d H:i')),
                ],
            ],
        ];

        if ($button = self::linkButton('查看用量', $linkUrl)) {
            $elements[] = $button;
        }

        $elements[] = ['tag' => 'hr'];
        $elements[] = [
            'tag' => 'note',
            'elements' => [[
                'tag' => 'plain_text',
                'content' => '余额持续低于阈值时每 ' . config('alerts.remind_hours') . ' 小时提醒一次，充值后自动恢复。',
            ]],
        ];

        return self::card('⚠️ 余额预警', 'red', $elements);
    }

    /**
     * 管理员汇总卡片：一次检查里所有命中的 Key 合并成一条，避免刷屏。
     *
     * @param  array<int, array{name: string, remain: int, threshold: int}>  $rows
     */
    public function adminSummaryCard(array $rows, ?string $linkUrl = null): array
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '**%s** — 余额 <font color=\'red\'>%s</font> / 阈值 %s',
                self::plain($row['name']),
                Quota::format($row['remain']),
                Quota::format($row['threshold'])
            );
        }

        $elements = [
            [
                'tag' => 'div',
                'text' => [
                    'tag' => 'lark_md',
                    'content' => '以下 ' . count($rows) . " 个 Key 余额已低于预警阈值：\n\n" . implode("\n", $lines),
                ],
            ],
        ];

        if ($button = self::linkButton('打开预警管理', $linkUrl)) {
            $elements[] = $button;
        }

        $elements[] = ['tag' => 'hr'];
        $elements[] = [
            'tag' => 'note',
            'elements' => [[
                'tag' => 'plain_text',
                'content' => '检查时间 ' . Carbon::now()->format('Y-m-d H:i'),
            ]],
        ];

        return self::card('⚠️ 余额预警汇总（管理员）', 'red', $elements);
    }

    /** 设置页「发送测试消息」用的卡片 */
    public function testCard(string $scene): array
    {
        return self::card('✅ 余额预警 · 测试消息', 'green', [
            [
                'tag' => 'div',
                'text' => [
                    'tag' => 'lark_md',
                    'content' => "{$scene}的飞书通知已配置成功，真正触发预警时你会在这里收到消息。",
                ],
            ],
            [
                'tag' => 'note',
                'elements' => [[
                    'tag' => 'plain_text',
                    'content' => '发送时间 ' . Carbon::now()->format('Y-m-d H:i:s'),
                ]],
            ],
        ]);
    }

    private static function card(string $title, string $template, array $elements): array
    {
        return [
            'msg_type' => 'interactive',
            'card' => [
                'config' => ['wide_screen_mode' => true],
                // 机器人若用「关键词」做安全设置，标题里的「余额预警」就是那个关键词
                'header' => [
                    'template' => $template,
                    'title' => ['tag' => 'plain_text', 'content' => $title],
                ],
                'elements' => $elements,
            ],
        ];
    }

    private static function field(string $label, string $value): array
    {
        return [
            'is_short' => true,
            'text' => ['tag' => 'lark_md', 'content' => "**{$label}**\n{$value}"],
        ];
    }

    /** 卡片链接按钮；地址不是 http(s) 就不渲染（飞书会整条消息拒收） */
    private static function linkButton(string $text, ?string $url): ?array
    {
        if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        return [
            'tag' => 'action',
            'actions' => [[
                'tag' => 'button',
                'text' => ['tag' => 'plain_text', 'content' => $text],
                'url' => $url,
                'type' => 'primary',
            ]],
        ];
    }

    /** Key 名称由用户自定，去掉换行和 markdown 里会捣乱的字符 */
    private static function plain(string $value): string
    {
        return trim(str_replace(["\r", "\n", '*', '`', '<', '>'], ' ', $value));
    }
}
