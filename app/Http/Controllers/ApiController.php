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

        $total = $query->count();
        $data = $query->paginate($pageSize, ['id', 'created_at', 'model_name', 'prompt_tokens', 'completion_tokens', 'quota'], 'page', $page);

        return response()->json($data->toArray());
    }
}
