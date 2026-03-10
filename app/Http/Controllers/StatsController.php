<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Token;

class StatsController extends Controller
{
    /**
     * 将 quota 转换为金额（quota / 500000）
     */
    private function quotaToAmount(int $quota): float
    {
        return round($quota / 500000, 4);
    }

    public function dashboard(Request $request)
    {
        $days = (int) $request->query('days', 7);
        if (!in_array($days, [1, 3, 7, 30, 90])) {
            $days = 1;
        }

        $since = Carbon::now()->subDays($days)->startOfDay();
        $sinceTimestamp = $since->timestamp;

        // 总览数据
        $overview = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(quota), 0) as total_quota')
            ->selectRaw('COUNT(DISTINCT token_name) as active_users')
            ->first();

        $overview->total_amount = $this->quotaToAmount($overview->total_quota);

        // Top 10 用户用量（按金额排序）
        $topUsers = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('token_name')
            ->selectRaw('token_name')
            ->selectRaw('COUNT(*) as request_count')
            ->selectRaw('SUM(prompt_tokens) as prompt_tokens')
            ->selectRaw('SUM(completion_tokens) as completion_tokens')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total_tokens')
            ->selectRaw('SUM(quota) as total_quota')
            ->orderByDesc('total_quota')
            ->limit(10)
            ->get();

        $topUserNames = $topUsers->pluck('token_name')->toArray();

        // Top 10 用户的主要模型
        $userModels = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->whereIn('token_name', $topUserNames)
            ->groupBy('token_name', 'model_name')
            ->selectRaw('token_name, model_name, SUM(prompt_tokens + completion_tokens) as tokens')
            ->orderByDesc('tokens')
            ->get()
            ->groupBy('token_name');

        // 为每个用户找到主要模型
        $primaryModels = [];
        foreach ($userModels as $name => $models) {
            $primaryModels[$name] = $models->first()->model_name;
        }

        // 模型使用分布（全局）
        $modelDistribution = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('model_name')
            ->selectRaw('model_name, SUM(prompt_tokens + completion_tokens) as total_tokens')
            ->orderByDesc('total_tokens')
            ->limit(10)
            ->get();

        // 每日用量趋势（Top 10 用户）
        $dailyTrend = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->whereIn('token_name', $topUserNames)
            ->groupBy('date', 'token_name')
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, token_name, SUM(prompt_tokens + completion_tokens) as daily_tokens, SUM(quota) as daily_quota')
            ->orderBy('date')
            ->get();

        // 每日总金额
        $dailyAmounts = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date')
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, SUM(quota) as daily_quota')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // 整理每日趋势数据为图表格式
        $dates = [];
        $current = $since->copy();
        $now = Carbon::now();
        while ($current->lte($now)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        $dailyData = [];
        $dailyAmountData = [];
        foreach ($topUserNames as $name) {
            $dailyData[$name] = array_fill_keys($dates, 0);
            $dailyAmountData[$name] = array_fill_keys($dates, 0);
        }
        foreach ($dailyTrend as $row) {
            if (isset($dailyData[$row->token_name])) {
                $dailyData[$row->token_name][$row->date] = (int) $row->daily_tokens;
                $dailyAmountData[$row->token_name][$row->date] = $this->quotaToAmount($row->daily_quota);
            }
        }

        return view('admin.dashboard', compact(
            'days',
            'overview',
            'topUsers',
            'primaryModels',
            'modelDistribution',
            'dates',
            'dailyData',
            'dailyAmountData',
            'topUserNames',
            'dailyAmounts'
        ));
    }

    /**
     * 用户详情页面 - 统计 Tab
     */
    public function userDetail(Request $request, string $tokenName)
    {
        $days = (int) $request->query('days', 7);
        if (!in_array($days, [1, 3,7, 30, 90])) {
            $days = 1;
        }

        $since = Carbon::now()->subDays($days)->startOfDay();
        $sinceTimestamp = $since->timestamp;

        // 用户总览统计
        $overview = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(quota), 0) as total_quota')
            ->selectRaw('COALESCE(SUM(prompt_tokens), 0) as total_prompt_tokens')
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) as total_completion_tokens')
            ->first();

        $overview->total_amount = $this->quotaToAmount($overview->total_quota);

        // 每日消费趋势（使用 FROM_UNIXTIME 转换时间戳）
        $dailyTrend = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date')
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date')
            ->selectRaw('SUM(quota) as daily_quota')
            ->selectRaw('SUM(prompt_tokens) as daily_prompt_tokens')
            ->selectRaw('SUM(completion_tokens) as daily_completion_tokens')
            ->selectRaw('COUNT(*) as daily_requests')
            ->orderBy('date')
            ->get();

        // 整理日期数据
        $dates = [];
        $current = $since->copy();
        $now = Carbon::now();
        while ($current->lte($now)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        $dailyData = [
            'amounts' => array_fill_keys($dates, 0),
            'requests' => array_fill_keys($dates, 0),
            'prompt_tokens' => array_fill_keys($dates, 0),
            'completion_tokens' => array_fill_keys($dates, 0),
        ];

        foreach ($dailyTrend as $row) {
            if (isset($dailyData['amounts'][$row->date])) {
                $dailyData['amounts'][$row->date] = $this->quotaToAmount($row->daily_quota);
                $dailyData['requests'][$row->date] = (int) $row->daily_requests;
                $dailyData['prompt_tokens'][$row->date] = (int) $row->daily_prompt_tokens;
                $dailyData['completion_tokens'][$row->date] = (int) $row->daily_completion_tokens;
            }
        }

        // 每日模型金额分布（用于堆叠柱图）
        $dailyModelTrend = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date', 'model_name')
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, model_name, SUM(quota) as daily_quota')
            ->orderBy('date')
            ->get();

        // 收集出现的模型名（按总金额排序）
        $modelAmounts = [];
        foreach ($dailyModelTrend as $row) {
            $modelAmounts[$row->model_name] = ($modelAmounts[$row->model_name] ?? 0) + $row->daily_quota;
        }
        arsort($modelAmounts);
        $dailyModelNames = array_keys($modelAmounts);

        // 整理为 { model_name: { date: amount, ... }, ... }
        $dailyModelData = [];
        foreach ($dailyModelNames as $model) {
            $dailyModelData[$model] = array_fill_keys($dates, 0);
        }
        foreach ($dailyModelTrend as $row) {
            if (isset($dailyModelData[$row->model_name][$row->date])) {
                $dailyModelData[$row->model_name][$row->date] = $this->quotaToAmount($row->daily_quota);
            }
        }

        // 模型使用分布
        $modelDistribution = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('model_name')
            ->selectRaw('model_name')
            ->selectRaw('SUM(quota) as total_quota')
            ->selectRaw('COUNT(*) as request_count')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total_tokens')
            ->orderByDesc('total_quota')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $item->total_amount = $this->quotaToAmount($item->total_quota);
                return $item;
            });

        // 分组使用分布
        $groupDistribution = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->whereNotNull('group')
            ->where('group', '!=', '')
            ->groupBy('group')
            ->selectRaw('`group`')
            ->selectRaw('SUM(quota) as total_quota')
            ->selectRaw('COUNT(*) as request_count')
            ->orderByDesc('total_quota')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $item->total_amount = $this->quotaToAmount($item->total_quota);
                return $item;
            });

        return view('admin.user-detail', compact(
            'tokenName',
            'days',
            'overview',
            'dates',
            'dailyData',
            'dailyModelData',
            'dailyModelNames',
            'modelDistribution',
            'groupDistribution'
        ));
    }

    /**
     * 用户日志列表 API
     */
    public function userLogs(Request $request, string $tokenName)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(10, (int) $request->query('pageSize', 20)));

        $query = DB::table('logs')
            ->where('token_name', $tokenName)
            ->orderByDesc('created_at');

        $total = (clone $query)->count();
        $logs = $query
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'created_at' => date('Y-m-d H:i:s', $log->created_at),
                    'model_name' => $log->model_name,
                    'group' => $log->group,
                    'prompt_tokens' => $log->prompt_tokens,
                    'completion_tokens' => $log->completion_tokens,
                    'quota' => $log->quota,
                    'amount' => $this->quotaToAmount($log->quota),
                    'use_time' => $log->use_time,
                    'is_stream' => (bool) $log->is_stream,
                    'content' => $log->content,
                ];
            });

        return response()->json([
            'data' => $logs,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => (int) ceil($total / $pageSize),
        ]);
    }

    /**
     * 公开访问用户详情页面（通过 API Key）
     */
    public function publicUserDetail(Request $request, string $apikey)
    {
        $processedKey = substr($apikey, 3);
        $token = Token::where('key', $processedKey)->first();

        if (!$token) {
            abort(404);
        }

        $tokenName = $token->name;
        $balance = $token->unlimited_quota
            ? '无限'
            : '$' . number_format($token->remain_quota / 500000, 4);

        $days = (int) $request->query('days', 7);
        if (!in_array($days, [1, 3, 7, 30, 90])) {
            $days = 1;
        }

        $since = Carbon::now()->subDays($days)->startOfDay();
        $sinceTimestamp = $since->timestamp;

        $overview = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(quota), 0) as total_quota')
            ->selectRaw('COALESCE(SUM(prompt_tokens), 0) as total_prompt_tokens')
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) as total_completion_tokens')
            ->first();

        $overview->total_amount = $this->quotaToAmount($overview->total_quota);

        $dailyTrend = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date')
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date')
            ->selectRaw('SUM(quota) as daily_quota')
            ->selectRaw('SUM(prompt_tokens) as daily_prompt_tokens')
            ->selectRaw('SUM(completion_tokens) as daily_completion_tokens')
            ->selectRaw('COUNT(*) as daily_requests')
            ->orderBy('date')
            ->get();

        $dates = [];
        $current = $since->copy();
        $now = Carbon::now();
        while ($current->lte($now)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        $dailyData = [
            'amounts' => array_fill_keys($dates, 0),
            'requests' => array_fill_keys($dates, 0),
            'prompt_tokens' => array_fill_keys($dates, 0),
            'completion_tokens' => array_fill_keys($dates, 0),
        ];

        foreach ($dailyTrend as $row) {
            if (isset($dailyData['amounts'][$row->date])) {
                $dailyData['amounts'][$row->date] = $this->quotaToAmount($row->daily_quota);
                $dailyData['requests'][$row->date] = (int) $row->daily_requests;
                $dailyData['prompt_tokens'][$row->date] = (int) $row->daily_prompt_tokens;
                $dailyData['completion_tokens'][$row->date] = (int) $row->daily_completion_tokens;
            }
        }

        $dailyModelTrend = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date', 'model_name')
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, model_name, SUM(quota) as daily_quota')
            ->orderBy('date')
            ->get();

        $modelAmounts = [];
        foreach ($dailyModelTrend as $row) {
            $modelAmounts[$row->model_name] = ($modelAmounts[$row->model_name] ?? 0) + $row->daily_quota;
        }
        arsort($modelAmounts);
        $dailyModelNames = array_keys($modelAmounts);

        $dailyModelData = [];
        foreach ($dailyModelNames as $model) {
            $dailyModelData[$model] = array_fill_keys($dates, 0);
        }
        foreach ($dailyModelTrend as $row) {
            if (isset($dailyModelData[$row->model_name][$row->date])) {
                $dailyModelData[$row->model_name][$row->date] = $this->quotaToAmount($row->daily_quota);
            }
        }

        $modelDistribution = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('model_name')
            ->selectRaw('model_name')
            ->selectRaw('SUM(quota) as total_quota')
            ->selectRaw('COUNT(*) as request_count')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total_tokens')
            ->orderByDesc('total_quota')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $item->total_amount = $this->quotaToAmount($item->total_quota);
                return $item;
            });

        $groupDistribution = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->whereNotNull('group')
            ->where('group', '!=', '')
            ->groupBy('group')
            ->selectRaw('`group`')
            ->selectRaw('SUM(quota) as total_quota')
            ->selectRaw('COUNT(*) as request_count')
            ->orderByDesc('total_quota')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $item->total_amount = $this->quotaToAmount($item->total_quota);
                return $item;
            });

        $isPublic = true;

        return view('admin.user-detail', compact(
            'tokenName',
            'days',
            'overview',
            'dates',
            'dailyData',
            'dailyModelData',
            'dailyModelNames',
            'modelDistribution',
            'groupDistribution',
            'balance',
            'isPublic',
            'apikey'
        ));
    }

    /**
     * 公开访问用户日志 API（通过 API Key）
     */
    public function publicUserLogs(Request $request, string $apikey)
    {
        $processedKey = substr($apikey, 3);
        $token = Token::where('key', $processedKey)->first();

        if (!$token) {
            abort(404);
        }

        return $this->userLogs($request, $token->name);
    }
}
