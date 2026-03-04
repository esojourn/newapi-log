<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>统计仪表盘 - API Log</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    {{-- 顶部导航 --}}
    <nav class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-800">API 用量统计</h1>
            <div class="flex items-center gap-3">
                {{-- 时间范围切换 --}}
                <div class="flex rounded-md shadow-sm">
                    @foreach ([1, 3, 7, 30, 90] as $d)
                        <a href="?days={{ $d }}"
                            class="px-3 py-1.5 text-sm border {{ $days == $d ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }} {{ $d == 7 ? 'rounded-l-md' : '' }} {{ $d == 90 ? 'rounded-r-md' : '' }}">
                            {{ $d }}天
                        </a>
                    @endforeach
                </div>
                {{-- 登出 --}}
                <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-sm text-gray-500 hover:text-red-600 transition">登出</button>
                </form>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">
        {{-- 总览卡片 --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-sm text-gray-500">总请求数</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->total_requests) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-sm text-gray-500">总 Token 数</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->total_tokens) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-sm text-gray-500">活跃用户数</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->active_users) }}</div>
            </div>
        </div>

        {{-- 数据表格 --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-5 py-4 border-b">
                <h2 class="text-lg font-semibold text-gray-800">Top 10 用户用量排行</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="text-left px-5 py-3 font-medium">#</th>
                            <th class="text-left px-5 py-3 font-medium">用户</th>
                            <th class="text-right px-5 py-3 font-medium">请求数</th>
                            <th class="text-right px-5 py-3 font-medium">Prompt Tokens</th>
                            <th class="text-right px-5 py-3 font-medium">Completion Tokens</th>
                            <th class="text-right px-5 py-3 font-medium">总 Tokens</th>
                            <th class="text-left px-5 py-3 font-medium">主要模型</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($topUsers as $i => $user)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-5 py-3 font-medium text-blue-600 hover:text-blue-800">
                                    <a href="{{ route('admin.user.detail', ['tokenName' => $user->token_name]) }}">
                                        {{ $user->token_name }}
                                    </a>
                                </td>
                                <td class="px-5 py-3 text-right text-gray-700">{{ number_format($user->request_count) }}</td>
                                <td class="px-5 py-3 text-right text-gray-700">{{ number_format($user->prompt_tokens) }}</td>
                                <td class="px-5 py-3 text-right text-gray-700">{{ number_format($user->completion_tokens) }}</td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-800">{{ number_format($user->total_tokens) }}</td>
                                <td class="px-5 py-3 text-gray-600">{{ $primaryModels[$user->token_name] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 图表区域 --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- 柱状图：Top 10 用户 Token 用量 --}}
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">用户 Token 用量对比</h2>
                <canvas id="barChart"></canvas>
            </div>

            {{-- 饼图：模型使用分布 --}}
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">模型使用分布</h2>
                <canvas id="pieChart"></canvas>
            </div>
        </div>

        {{-- 折线图：每日用量趋势 --}}
        <div class="bg-white rounded-lg shadow p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">每日用量趋势（Top 10 用户）</h2>
            <canvas id="lineChart"></canvas>
        </div>
    </div>

    <script>
        const COLORS = [
            '#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6',
            '#EC4899', '#06B6D4', '#84CC16', '#F97316', '#6366F1'
        ];

        // 柱状图
        new Chart(document.getElementById('barChart'), {
            type: 'bar',
            data: {
                labels: @json($topUsers->pluck('token_name')),
                datasets: [
                    {
                        label: 'Prompt Tokens',
                        data: @json($topUsers->pluck('prompt_tokens')),
                        backgroundColor: '#3B82F6',
                    },
                    {
                        label: 'Completion Tokens',
                        data: @json($topUsers->pluck('completion_tokens')),
                        backgroundColor: '#10B981',
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } }
                }
            }
        });

        // 饼图
        new Chart(document.getElementById('pieChart'), {
            type: 'doughnut',
            data: {
                labels: @json($modelDistribution->pluck('model_name')),
                datasets: [{
                    data: @json($modelDistribution->pluck('total_tokens')),
                    backgroundColor: COLORS,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'right' } }
            }
        });

        // 折线图
        const dailyData = @json($dailyData);
        const userNames = @json($topUserNames);
        new Chart(document.getElementById('lineChart'), {
            type: 'line',
            data: {
                labels: @json($dates),
                datasets: userNames.map((name, i) => ({
                    label: name,
                    data: Object.values(dailyData[name] || {}),
                    borderColor: COLORS[i % COLORS.length],
                    backgroundColor: 'transparent',
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 1,
                }))
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: { ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } }
                }
            }
        });
    </script>
</body>
</html>
