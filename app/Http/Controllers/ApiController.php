<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Log;
use App\Models\Token;

class ApiController extends Controller
{
    public function getLogs(Request $request)
    {
        $authToken = $request->header('Authorization');
        if (!$authToken) {
            return response()->json(['error' => '未登录且未提供 access token'], 401);
        }

        // 兼容 "Bearer " 和 "bearer " 前缀
        $token = preg_replace('/^bearer\s+/i', '', trim($authToken));
        $token = substr($token, 3);

        if (!$token) {
            return response()->json(['error' => 'Access token 格式无效'], 401);
        }

        $token_data = Token::where('key', $token)->first();
        if (!$token_data) {
            return response()->json(['error' => 'Access token 不存在'], 401);
        }

        $page = (int) $request->query('page', 1);
        $pageSize = min((int) $request->query('pageSize', 10), 1000);

        $query = Log::orderBy('created_at', 'desc')
            ->where('token_name', $token_data->name);

        // other 仅用于解析缓存明细，不原样返回：其中含 admin_info 等内部字段
        $data = $query->paginate(
            $pageSize,
            ['id', 'created_at', 'model_name', 'prompt_tokens', 'completion_tokens', 'quota', 'other'],
            'page',
            $page
        );

        $data->through(function ($log) {
            $cache = Log::cacheTokens($log);

            return [
                'id' => $log->id,
                'created_at' => $log->created_at,
                'model_name' => $log->model_name,
                'prompt_tokens' => $log->prompt_tokens,
                'cache_tokens' => $cache['cache_tokens'],
                'cache_creation_tokens' => $cache['cache_creation_tokens'],
                'completion_tokens' => $log->completion_tokens,
                'quota' => $log->quota,
            ];
        });

        return response()->json($data->toArray());
    }
}
