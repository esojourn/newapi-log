<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>统计仪表盘 - API Log</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        body { background-color: #E7F8FF; }
        .alz-nav { background: white; border-bottom: 2px solid #1D93AB; box-shadow: 0 1px 3px rgba(29,147,171,0.1); }
        .alz-btn-active { background-color: #1D93AB !important; color: white !important; border-color: #1D93AB !important; }
        .alz-btn-day:hover { background-color: #e8f7fc !important; }
        .alz-thead { background-color: #f0fafc; color: #0f5a6b; }
        .alz-tr:hover { background-color: #e8f7fc; }
        .alz-link { color: #1D93AB; }
        .alz-link:hover { color: #0f5a6b; }
        .alz-divider { border-color: #d0eff5; }
    </style>
</head>
<body class="min-h-screen">
    {{-- 顶部导航 --}}
    <nav class="alz-nav">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-bold text-gray-800">API 用量统计</h1>
            <div class="flex items-center gap-3">
                {{-- 时间范围切换 + 基准时间 --}}
                @include('partials.range-picker')
                {{-- 预警通知 --}}
                <a href="{{ route('admin.alerts') }}" class="text-sm text-gray-500 hover:text-gray-700 transition">预警通知</a>
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
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-sm text-gray-500">总请求数</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->total_requests) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-sm text-gray-500">总 Token 数</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->total_tokens) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-sm text-gray-500">总金额</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">${{ number_format($overview->total_amount, 4) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5">
                <div class="text-sm text-gray-500">活跃用户数</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->active_users) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5" title="缓存命中率 = 命中缓存的输入 Tokens / 总输入 Tokens">
                <div class="text-sm text-gray-500">缓存命中率</div>
                <div class="text-2xl font-bold mt-1" style="color:#1D93AB;">
                    {{ $overview->cache_hit_rate === null ? '-' : $overview->cache_hit_rate . '%' }}
                </div>
                <div class="text-xs text-gray-400 mt-1">预估节省 ${{ number_format($overview->cache_saved_amount, 4) }}</div>
            </div>
        </div>

        {{-- 数据表格 --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-5 py-4 border-b">
                <h2 class="text-lg font-semibold text-gray-800">Top 10 用户用量排行</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="alz-thead">
                        <tr>
                            <th class="text-left px-5 py-3 font-medium">#</th>
                            <th class="text-left px-5 py-3 font-medium">用户</th>
                            <th class="text-right px-5 py-3 font-medium">请求数</th>
                            <th class="text-right px-5 py-3 font-medium">Prompt Tokens</th>
                            <th class="text-right px-5 py-3 font-medium">Completion Tokens</th>
                            <th class="text-right px-5 py-3 font-medium">总 Tokens</th>
                            <th class="text-right px-5 py-3 font-medium">金额</th>
                            <th class="text-left px-5 py-3 font-medium">主要模型</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y alz-divider">
                        @foreach ($topUsers as $i => $user)
                            <tr class="alz-tr">
                                <td class="px-5 py-3 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-5 py-3 font-medium alz-link">
                                    <a href="{{ route('admin.user.detail', ['tokenName' => $user->token_name, 'days' => $days, $range['param'] => $range['anchored'] ? $range['value'] : null]) }}">
                                        {{ $user->token_name }}
                                    </a>
                                </td>
                                <td class="px-5 py-3 text-right text-gray-700">{{ number_format($user->request_count) }}</td>
                                <td class="px-5 py-3 text-right text-gray-700">{{ number_format($user->prompt_tokens) }}</td>
                                <td class="px-5 py-3 text-right text-gray-700">{{ number_format($user->completion_tokens) }}</td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-800">{{ number_format($user->total_tokens) }}</td>
                                <td class="px-5 py-3 text-right text-gray-700">${{ number_format(round($user->total_quota / 500000, 4), 4) }}</td>
                                <td class="px-5 py-3 text-gray-600">{{ $primaryModels[$user->token_name] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 图表区域 --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- 混合图：Top 10 用户 Token 用量 + 金额曲线 --}}
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">用户 Token 用量与金额对比</h2>
                <canvas id="barChart"></canvas>
            </div>

            {{-- 饼图：模型使用分布 --}}
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">模型使用分布</h2>
                <canvas id="pieChart"></canvas>
            </div>
        </div>

        {{-- 混合图：Top 10 用户 Token 折线 + 全站总金额柱图 --}}
        <div class="bg-white rounded-lg shadow p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ $hourly ? '每小时' : '每日' }}用量趋势（Top 10 用户）</h2>
            <p class="text-xs text-gray-400 mb-4">折线显示 Top 10 用户的 Token 用量，柱图显示全部用户的总金额。</p>
            <div class="relative" style="height: 300px;">
                <canvas id="lineChart"></canvas>
            </div>
        </div>

        {{-- 缓存利用率趋势 --}}
        <div class="bg-white rounded-lg shadow p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-1">缓存利用率趋势</h2>
            <p class="text-xs text-gray-400 mb-4">
                输入 Tokens 按缓存状态拆分（三段之和 = {{ $hourly ? '该时段' : '当日' }}总输入）。缓存读取按折扣计费，缓存写入通常按 1.25 倍计费。
                请求数折线随模型筛选切换，全部模型显示汇总请求数。
                <span id="cacheScopeLabel">全部模型</span> 区间内预估节省
                <span id="cacheSavedAmount" class="font-semibold" style="color:#1D93AB;">${{ number_format($overview->cache_saved_amount, 4) }}</span>。
            </p>
            <div class="relative" style="height: 300px;">
                <canvas id="cacheChart"></canvas>
            </div>

            {{-- 模型筛选（用量前 5 的模型） --}}
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" data-model="" class="cache-model-btn px-3 py-1.5 text-sm border rounded-md alz-btn-active">
                    全部模型
                </button>
                @foreach ($cacheModelNames as $model)
                    <button type="button" data-model="{{ $model }}"
                        class="cache-model-btn px-3 py-1.5 text-sm border rounded-md bg-white text-gray-700 border-gray-300 alz-btn-day">
                        {{ $model }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <script>
        const COLORS = [
            '#1D93AB', '#2DD4BF', '#0EA5E9', '#6366F1', '#8B5CF6',
            '#A78BFA', '#F59E0B', '#10B981', '#EC4899', '#64748B'
        ];

        // 混合图：柱状图 + 折线图
        new Chart(document.getElementById('barChart'), {
            type: 'bar',
            data: {
                labels: @json($topUsers->pluck('token_name')),
                datasets: [
                    {
                        label: 'Prompt Tokens',
                        data: @json($topUsers->pluck('prompt_tokens')),
                        backgroundColor: '#1D93AB',
                        yAxisID: 'y',
                    },
                    {
                        label: 'Completion Tokens',
                        data: @json($topUsers->pluck('completion_tokens')),
                        backgroundColor: '#64C8D8',
                        yAxisID: 'y',
                    },
                    {
                        label: '消费金额 ($)',
                        data: @json($topUsers->map(fn($u) => round($u->total_quota / 500000, 4))),
                        type: 'line',
                        borderColor: '#6366F1',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.3,
                        yAxisID: 'y1',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.dataset.yAxisID === 'y1') {
                                    label += '$' + context.parsed.y.toFixed(4);
                                } else {
                                    const val = context.parsed.y;
                                    label += val >= 1e6 ? (val/1e6).toFixed(1)+'M' : val >= 1e3 ? (val/1e3).toFixed(0)+'K' : val;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        stacked: true,
                        ticks: {
                            callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v
                        },
                        title: {
                            display: true,
                            text: 'Tokens'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                        ticks: {
                            callback: v => '$' + v.toFixed(2)
                        },
                        title: {
                            display: true,
                            text: '金额 ($)'
                        }
                    }
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

        // 混合图：保留 Top 10 用户 Token 折线，原金额表格改为全站总金额柱图
        const dates = @json($dates);
        const dailyData = @json($dailyData);
        const dailyTotalAmountData = @json($dailyTotalAmountData);
        const userNames = @json($topUserNames);
        new Chart(document.getElementById('lineChart'), {
            type: 'bar',
            data: {
                labels: dates,
                datasets: [
                    ...userNames.map((name, i) => ({
                        type: 'line',
                        label: name,
                        data: dates.map(date => dailyData[name]?.[date] ?? 0),
                        borderColor: COLORS[i % COLORS.length],
                        backgroundColor: 'transparent',
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 1,
                        yAxisID: 'y',
                        order: 1,
                    })),
                    {
                        type: 'bar',
                        label: '总金额（全部用户，$）',
                        data: dates.map(date => dailyTotalAmountData[date] ?? 0),
                        backgroundColor: 'rgba(100, 116, 139, 0.25)',
                        borderColor: '#94A3B8',
                        borderWidth: 1,
                        yAxisID: 'y1',
                        order: 2,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.dataset.yAxisID === 'y1'
                                ? `${ctx.dataset.label}: $${ctx.parsed.y.toFixed(4)}`
                                : `${ctx.dataset.label}: ${ctx.parsed.y.toLocaleString()} Tokens`
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        position: 'left',
                        beginAtZero: true,
                        title: { display: true, text: 'Tokens' },
                        ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        title: { display: true, text: '金额 ($)' },
                        grid: { drawOnChartArea: false },
                        ticks: { callback: v => '$' + v.toFixed(2) }
                    }
                }
            }
        });

        // 缓存利用率趋势：输入 Tokens 按缓存状态堆叠 + 命中率和请求数折线
        const cacheData = @json($dailyCacheData);
        const fmtTokens = v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v;

        const modelCacheData = @json($modelCacheData);
        const modelCacheSaved = @json($modelCacheSaved);
        const allCacheSaved = {{ $overview->cache_saved_amount }};

        // 从同一模型范围的序列里拆出三段柱子、命中率与请求数折线
        // 命中率 = 缓存读取 / 当日总输入；当日无输入时留空（折线断开）
        function cacheSeries(source) {
            const read = Object.values(source.cache_tokens);
            const write = Object.values(source.cache_creation_tokens);
            const miss = Object.values(source.uncached_prompt_tokens);
            const rate = read.map((r, i) => {
                const total = r + write[i] + miss[i];
                return total > 0 ? +(r / total * 100).toFixed(1) : null;
            });
            const requests = dates.map(date => source.request_count[date] ?? 0);
            return { read, write, miss, rate, requests };
        }

        const initialCache = cacheSeries(cacheData);
        const cacheRead = initialCache.read;
        const cacheWrite = initialCache.write;
        const cacheMiss = initialCache.miss;
        const cacheHitRate = initialCache.rate;

        const cacheChart = new Chart(document.getElementById('cacheChart'), {
            type: 'bar',
            data: {
                labels: @json($dates),
                datasets: [
                    { label: '缓存读取', data: cacheRead, backgroundColor: '#1D93AB', stack: 'tokens', yAxisID: 'y', order: 2 },
                    { label: '缓存写入', data: cacheWrite, backgroundColor: '#F59E0B', stack: 'tokens', yAxisID: 'y', order: 2 },
                    { label: '未命中输入', data: cacheMiss, backgroundColor: '#CBD5E1', stack: 'tokens', yAxisID: 'y', order: 2 },
                    {
                        label: '命中率 (%)',
                        data: cacheHitRate,
                        type: 'line',
                        borderColor: '#6366F1',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.3,
                        yAxisID: 'y1',
                        order: 1,
                        pointRadius: 2,
                        spanGaps: true,
                    },
                    {
                        label: '请求数',
                        data: initialCache.requests,
                        type: 'line',
                        borderColor: '#E11D48',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [5, 3],
                        tension: 0.3,
                        yAxisID: 'y2',
                        order: 0,
                        pointRadius: 2,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.dataset.yAxisID === 'y1'
                                ? `${ctx.dataset.label}: ${ctx.raw === null ? '-' : ctx.raw + '%'}`
                                : ctx.dataset.yAxisID === 'y2'
                                ? `${ctx.dataset.label}: ${ctx.raw.toLocaleString()} 次`
                                : `${ctx.dataset.label}: ${ctx.raw.toLocaleString()}`
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: {
                        type: 'linear',
                        position: 'left',
                        stacked: true,
                        title: { display: true, text: '输入 Tokens' },
                        ticks: { callback: fmtTokens }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        min: 0,
                        max: 100,
                        title: { display: true, text: '命中率 (%)' },
                        grid: { drawOnChartArea: false },
                        ticks: { callback: v => v + '%' }
                    },
                    y2: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        title: { display: true, text: '请求数', color: '#E11D48' },
                        grid: { drawOnChartArea: false },
                        ticks: { precision: 0, color: '#E11D48', callback: v => v.toLocaleString() }
                    }
                }
            }
        });

        // 模型筛选：空值代表全部模型，切换时只换数据不重建图表
        const fmtAmount = v => v.toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
        const cacheScopeLabel = document.getElementById('cacheScopeLabel');
        const cacheSavedAmount = document.getElementById('cacheSavedAmount');
        const cacheModelBtns = document.querySelectorAll('.cache-model-btn');

        cacheModelBtns.forEach(btn => btn.addEventListener('click', () => {
            const model = btn.dataset.model;
            const source = model ? modelCacheData[model] : cacheData;
            if (!source) return;

            const series = cacheSeries(source);
            cacheChart.data.datasets[0].data = series.read;
            cacheChart.data.datasets[1].data = series.write;
            cacheChart.data.datasets[2].data = series.miss;
            cacheChart.data.datasets[3].data = series.rate;
            cacheChart.data.datasets[4].data = series.requests;
            cacheChart.update();

            cacheScopeLabel.textContent = model || '全部模型';
            cacheSavedAmount.textContent = '$' + fmtAmount(model ? modelCacheSaved[model] : allCacheSaved);

            cacheModelBtns.forEach(other => {
                const active = other === btn;
                other.classList.toggle('alz-btn-active', active);
                other.classList.toggle('bg-white', !active);
                other.classList.toggle('text-gray-700', !active);
                other.classList.toggle('border-gray-300', !active);
                other.classList.toggle('alz-btn-day', !active);
            });
        }));
    </script>
</body>
</html>
