<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Log;
use App\Models\Token;

class ApiController extends Controller
{
    /**
     * 分页查询当前 Token 的调用日志
     */
    public function getLogs(Request $request)
    {
        $token_data = $this->resolveToken($request);
        if ($token_data instanceof JsonResponse) {
            return $token_data;
        }

        $page = (int) $request->query('page', 1);
        $pageSize = min((int) $request->query('pageSize', 10), 1000);

        $query = Log::orderBy('created_at', 'desc')
            ->where('token_name', $token_data->name);

        $data = $query->paginate($pageSize, ['id', 'created_at', 'model_name', 'prompt_tokens', 'completion_tokens', 'quota'], 'page', $page);

        return response()->json($data->toArray());
    }

    /**
     * 查询当前 Token 的账户余额
     */
    public function getBalance(Request $request)
    {
        $token_data = $this->resolveToken($request);
        if ($token_data instanceof JsonResponse) {
            return $token_data;
        }

        $remainQuota = (int) $token_data->remain_quota;

        return response()->json([
            'unlimited' => (bool) $token_data->unlimited_quota,
            'remain_quota' => $remainQuota,
            // 与后台统计一致：金额 = quota / 500000
            'balance' => round($remainQuota / 500000, 4),
        ]);
    }

    /**
     * 从 Authorization 头解析并校验 Token
     * 成功返回 Token 模型，失败返回 401 JsonResponse
     */
    private function resolveToken(Request $request)
    {
        $authToken = $request->header('Authorization');
        if (!$authToken) {
            return response()->json(['error' => '未登录且未提供 access token'], 401);
        }

        // 兼容 "Bearer " / "bearer " 前缀，再去掉 "sk-" 前 3 位
        $token = preg_replace('/^bearer\s+/i', '', trim($authToken));
        $token = substr($token, 3);

        if (!$token) {
            return response()->json(['error' => 'Access token 格式无效'], 401);
        }

        $token_data = Token::where('key', $token)->first();
        if (!$token_data) {
            return response()->json(['error' => 'Access token 不存在'], 401);
        }

        return $token_data;
    }
}
