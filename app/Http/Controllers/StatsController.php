<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StatsController extends Controller
{
    public function dashboard(Request $request)
    {
        $days = (int) $request->query('days', 7);
        if (!in_array($days, [7, 30, 90])) {
            $days = 7;
        }

        $since = Carbon::now()->subDays($days)->startOfDay();

        // 总览数据
        $overview = DB::table('logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(prompt_tokens + completion_tokens), 0) as total_tokens')
            ->selectRaw('COUNT(DISTINCT token_name) as active_users')
            ->first();

        // Top 10 用户用量
        $topUsers = DB::table('logs')
            ->where('created_at', '>=', $since)
            ->groupBy('token_name')
            ->selectRaw('token_name')
            ->selectRaw('COUNT(*) as request_count')
            ->selectRaw('SUM(prompt_tokens) as prompt_tokens')
            ->selectRaw('SUM(completion_tokens) as completion_tokens')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total_tokens')
            ->orderByDesc('total_tokens')
            ->limit(10)
            ->get();

        $topUserNames = $topUsers->pluck('token_name')->toArray();

        // Top 10 用户的主要模型
        $userModels = DB::table('logs')
            ->where('created_at', '>=', $since)
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
            ->where('created_at', '>=', $since)
            ->groupBy('model_name')
            ->selectRaw('model_name, SUM(prompt_tokens + completion_tokens) as total_tokens')
            ->orderByDesc('total_tokens')
            ->limit(10)
            ->get();

        // 每日用量趋势（Top 10 用户）
        $dailyTrend = DB::table('logs')
            ->where('created_at', '>=', $since)
            ->whereIn('token_name', $topUserNames)
            ->groupBy('date', 'token_name')
            ->selectRaw('DATE(created_at) as date, token_name, SUM(prompt_tokens + completion_tokens) as daily_tokens')
            ->orderBy('date')
            ->get();

        // 整理每日趋势数据为图表格式
        $dates = [];
        $current = $since->copy();
        $now = Carbon::now();
        while ($current->lte($now)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        $dailyData = [];
        foreach ($topUserNames as $name) {
            $dailyData[$name] = array_fill_keys($dates, 0);
        }
        foreach ($dailyTrend as $row) {
            if (isset($dailyData[$row->token_name])) {
                $dailyData[$row->token_name][$row->date] = (int) $row->daily_tokens;
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
            'topUserNames'
        ));
    }
}
