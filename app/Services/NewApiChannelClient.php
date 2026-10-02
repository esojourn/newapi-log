<?php

namespace App\Services;

use App\Support\UpstreamMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** 由 NewAPI 自身完成测试与启用，保留渠道协议、模型映射及路由缓存的语义。 */
class NewApiChannelClient
{
    public const STATUS_ENABLED = 1;
    public const STATUS_AUTO_DISABLED = 3;

    private ?array $configuration = null;

    public function configure(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    private function option(string $key)
    {
        return ($this->configuration ?? config('channels'))[$key] ?? null;
    }

    public static function isValidBaseUrl(string $value): bool
    {
        $url = parse_url($value);

        return $url !== false && !empty($url['host'])
            && in_array($url['scheme'] ?? '', ['http', 'https'], true)
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']) && !isset($url['fragment']);
    }

    public function validateConfiguration(): void
    {
        if (!self::isValidBaseUrl((string) $this->option('base_url'))) {
            throw new RuntimeException('请配置 NEW_API_BASE_URL 为 NewAPI 实例地址（不含 /api/channel 或 /v1）。');
        }

        if (trim((string) $this->option('access_token')) === ''
            || !ctype_digit((string) $this->option('user_id'))
            || (int) $this->option('user_id') < 1) {
            throw new RuntimeException('请配置 NEW_API_ACCESS_TOKEN（管理员访问令牌）和 NEW_API_USER_ID。');
        }

        if ((int) $this->option('http_timeout') < 1) {
            throw new RuntimeException('CHANNEL_RECOVERY_HTTP_TIMEOUT 必须大于 0。');
        }
    }

    public function channel(int $id): array
    {
        $payload = $this->successfulPayload($this->request('GET', '/api/channel/' . $id));
        $channel = $payload['data'] ?? null;

        if (!is_array($channel) || (int) ($channel['id'] ?? 0) !== $id
            || !isset($channel['status'])) {
            throw new RuntimeException('NewAPI 渠道详情格式无效。');
        }

        return $channel;
    }

    public function isEligible(array $channel): bool
    {
        return in_array($channel['status'] ?? null, [self::STATUS_AUTO_DISABLED, '3'], true)
            && in_array($channel['auto_ban'] ?? null, [1, '1'], true);
    }

    /** @return array{disabled_at: ?Carbon, disabled_reason: ?string} */
    public function disabledDetails(array $channel): array
    {
        $info = $channel['other_info'] ?? null;
        if (is_string($info)) {
            $info = json_decode($info, true);
        }
        $info = is_array($info) ? $info : [];
        $timestamp = $info['status_time'] ?? null;
        $validTimestamp = (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp)))
            && $timestamp > 0 && $timestamp <= 253402300799;

        return [
            'disabled_at' => $validTimestamp ? Carbon::createFromTimestamp((int) $timestamp, config('app.timezone')) : null,
            'disabled_reason' => $this->sanitizeMessage($info['status_reason'] ?? null, $channel),
        ];
    }

    /** @return array{success: bool, message: ?string} */
    public function test(int $id, array $channel = []): array
    {
        // 不传 model，交给 NewAPI 使用该渠道的 test_model 和协议适配器。
        $response = $this->request('GET', '/api/channel/test/' . $id);
        $payload = $response->json();
        $message = $this->responseMessage(is_array($payload) ? $payload : [], $channel);

        if (!$response->successful() || !is_array($payload)
            || !is_bool($payload['success'] ?? null)) {
            throw new RuntimeException('NewAPI 测试接口响应无效，HTTP ' . $response->status() . '。'
                . ($message !== null ? ' 上游提示：' . $message : ''));
        }

        return ['success' => $payload['success'], 'message' => $message];
    }

    private function responseMessage(array $payload, array $channel): ?string
    {
        $error = $payload['error'] ?? null;
        $messages = array_filter([
            $this->sanitizeMessage($payload['message'] ?? null, $channel),
            $this->sanitizeMessage(is_array($error) ? ($error['message'] ?? null) : $error, $channel),
        ], fn ($value) => $value !== null);

        return $messages ? UpstreamMessage::sanitize(implode("\n", array_unique($messages))) : null;
    }

    private function sanitizeMessage($message, array $channel): ?string
    {
        $secrets = [trim((string) $this->option('access_token'))];
        if (is_string($channel['key'] ?? null)) {
            $secrets[] = $channel['key'];
            $keys = json_decode($channel['key'], true);
            $secrets = array_merge($secrets, is_array($keys) ? array_values($keys) : explode("\n", $channel['key']));
        }

        return UpstreamMessage::sanitize($message, $secrets);
    }

    /** 测试耗时期间可能有人改过渠道；每次状态写入前重新核对。 */
    public function enableIfEligible(int $id): bool
    {
        if (!$this->isEligible($this->channel($id))) {
            return false;
        }

        $response = $this->request('POST', '/api/channel/' . $id . '/status', [
            'status' => self::STATUS_ENABLED,
        ]);

        // 新版有独立状态接口；旧版仅在明确不支持该路由时使用传统更新接口。
        // 鉴权失败、超时或业务失败不重试写入，也不降级。
        if (in_array($response->status(), [404, 405], true)) {
            if (!$this->isEligible($this->channel($id))) {
                return false;
            }

            $response = $this->request('PUT', '/api/channel/', [
                'id' => $id,
                'status' => self::STATUS_ENABLED,
            ]);
        }

        $this->successfulPayload($response);

        if (!in_array($this->channel($id)['status'], [self::STATUS_ENABLED, '1'], true)) {
            throw new RuntimeException('NewAPI 更新后渠道仍未启用。');
        }

        return true;
    }

    private function request(string $method, string $path, array $body = []): Response
    {
        $this->validateConfiguration();

        try {
            return Http::acceptJson()
                ->withToken(trim((string) $this->option('access_token')))
                ->withHeaders(['New-Api-User' => (string) $this->option('user_id')])
                ->timeout((int) $this->option('http_timeout'))
                ->withOptions(['connect_timeout' => min(10, (int) $this->option('http_timeout'))])
                ->withoutRedirecting()
                ->send(
                    $method,
                    rtrim($this->option('base_url'), '/') . $path,
                    $method === 'GET' ? [] : ['json' => $body]
                );
        } catch (ConnectionException $e) {
            // 原始异常/响应可能带密钥或上游地址，不放进日志或命令输出。
            throw new RuntimeException('NewAPI 请求超时或连接失败。');
        }
    }

    private function successfulPayload(Response $response): array
    {
        $payload = $response->json();
        if (!$response->successful() || !is_array($payload) || ($payload['success'] ?? null) !== true) {
            throw new RuntimeException('NewAPI 管理接口请求失败，HTTP ' . $response->status() . '；请检查权限和接口兼容性。');
        }

        return $payload;
    }
}
