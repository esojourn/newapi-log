<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tokenName }} - 用户详情</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        .tab-btn.active { border-color: #3B82F6; color: #3B82F6; background-color: #EFF6FF; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    {{-- 顶部导航 --}}
    <nav class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.dashboard') }}" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <h1 class="text-xl font-bold text-gray-800">{{ $tokenName }}</h1>
            </div>
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
        {{-- Tab 切换 --}}
        <div class="border-b border-gray-200">
            <nav class="flex space-x-4">
                <button class="tab-btn active px-4 py-2 text-sm font-medium border-b-2 border-transparent" data-tab="stats">
                    统计
                </button>
                <button class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700" data-tab="logs">
                    日志
                </button>
            </nav>
        </div>

        {{-- 统计 Tab --}}
        <div id="stats-tab" class="tab-content active space-y-6">
            {{-- 总览卡片 --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-white rounded-lg shadow p-5">
                    <div class="text-sm text-gray-500">总请求数</div>
                    <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->total_requests) }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-5">
                    <div class="text-sm text-gray-500">消费金额</div>
                    <div class="text-2xl font-bold text-green-600 mt-1">¥{{ number_format($overview->total_amount, 4) }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-5">
                    <div class="text-sm text-gray-500">Prompt Tokens</div>
                    <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->total_prompt_tokens) }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-5">
                    <div class="text-sm text-gray-500">Completion Tokens</div>
                    <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($overview->total_completion_tokens) }}</div>
                </div>
            </div>

            {{-- 消费趋势图 --}}
            <div class="bg-white rounded-lg shadow p-5">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">每日消费趋势</h2>
                <canvas id="trendChart"></canvas>
            </div>

            {{-- 模型和分组分布 --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- 模型使用分布 --}}
                <div class="bg-white rounded-lg shadow p-5">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">模型使用分布</h2>
                    <canvas id="modelPieChart" class="mb-4"></canvas>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left px-3 py-2 font-medium">模型</th>
                                    <th class="text-right px-3 py-2 font-medium">请求数</th>
                                    <th class="text-right px-3 py-2 font-medium">金额</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($modelDistribution as $model)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 text-gray-800">{{ $model->model_name }}</td>
                                        <td class="px-3 py-2 text-right text-gray-700">{{ number_format($model->request_count) }}</td>
                                        <td class="px-3 py-2 text-right text-green-600">¥{{ number_format($model->total_amount, 4) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- 分组使用分布 --}}
                <div class="bg-white rounded-lg shadow p-5">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">分组使用分布</h2>
                    @if ($groupDistribution->isEmpty())
                        <div class="text-center text-gray-500 py-8">暂无分组数据</div>
                    @else
                        <canvas id="groupPieChart" class="mb-4"></canvas>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-left px-3 py-2 font-medium">分组</th>
                                        <th class="text-right px-3 py-2 font-medium">请求数</th>
                                        <th class="text-right px-3 py-2 font-medium">金额</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($groupDistribution as $group)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2 text-gray-800">{{ $group->group }}</td>
                                            <td class="px-3 py-2 text-right text-gray-700">{{ number_format($group->request_count) }}</td>
                                            <td class="px-3 py-2 text-right text-green-600">¥{{ number_format($group->total_amount, 4) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- 日志 Tab --}}
        <div id="logs-tab" class="tab-content">
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-5 py-4 border-b flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-800">消费日志</h2>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-500">每页</span>
                        <select id="pageSize" class="border border-gray-300 rounded px-2 py-1 text-sm">
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span class="text-sm text-gray-500">条</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="text-left px-4 py-3 font-medium">时间</th>
                                <th class="text-left px-4 py-3 font-medium">分组</th>
                                <th class="text-left px-4 py-3 font-medium">模型</th>
                                <th class="text-right px-4 py-3 font-medium">输入</th>
                                <th class="text-right px-4 py-3 font-medium">输出</th>
                                <th class="text-right px-4 py-3 font-medium">金额</th>
                            </tr>
                        </thead>
                        <tbody id="logsTableBody" class="divide-y divide-gray-100">
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">加载中...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                {{-- 分页 --}}
                <div class="px-5 py-4 border-t flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        共 <span id="totalCount">0</span> 条记录
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="prevBtn" class="px-3 py-1 border border-gray-300 rounded text-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                            上一页
                        </button>
                        <span class="text-sm text-gray-700">
                            第 <span id="currentPage">1</span> / <span id="totalPages">1</span> 页
                        </span>
                        <button id="nextBtn" class="px-3 py-1 border border-gray-300 rounded text-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                            下一页
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const COLORS = [
            '#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6',
            '#EC4899', '#06B6D4', '#84CC16', '#F97316', '#6366F1'
        ];

        // Tab 切换
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab + '-tab').classList.add('active');

                // 首次切换到日志 tab 时加载数据
                if (btn.dataset.tab === 'logs' && !logsLoaded) {
                    loadLogs();
                }
            });
        });

        // 消费趋势图（堆叠柱图 + 折线）
        const dailyData = @json($dailyData);
        const dailyModelData = @json($dailyModelData);
        const dailyModelNames = @json($dailyModelNames);
        const dates = @json($dates);

        // 为每个模型生成一个堆叠柱图 dataset
        const modelBarDatasets = dailyModelNames.map((model, i) => ({
            label: model,
            data: Object.values(dailyModelData[model] || {}),
            backgroundColor: COLORS[i % COLORS.length],
            stack: 'amount',
            yAxisID: 'y',
            order: 2
        }));

        // 请求数折线 dataset
        const requestLineDataset = {
            label: '请求数',
            data: Object.values(dailyData.requests),
            borderColor: '#3B82F6',
            backgroundColor: 'transparent',
            type: 'line',
            tension: 0.3,
            yAxisID: 'y1',
            order: 1,
            pointRadius: 2,
            borderWidth: 2
        };

        new Chart(document.getElementById('trendChart'), {
            type: 'bar',
            data: {
                labels: dates,
                datasets: [...modelBarDatasets, requestLineDataset]
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                if (ctx.dataset.type === 'line') {
                                    return `${ctx.dataset.label}: ${ctx.raw.toLocaleString()}`;
                                }
                                return `${ctx.dataset.label}: ¥${ctx.raw.toFixed(4)}`;
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
                        title: { display: true, text: '金额 (¥)' }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: { display: true, text: '请求数' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });

        // 模型饼图
        const modelData = @json($modelDistribution);
        new Chart(document.getElementById('modelPieChart'), {
            type: 'doughnut',
            data: {
                labels: modelData.map(m => m.model_name),
                datasets: [{
                    data: modelData.map(m => m.total_amount),
                    backgroundColor: COLORS
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.label}: ¥${ctx.raw.toFixed(4)}`
                        }
                    }
                }
            }
        });

        // 分组饼图
        @if (!$groupDistribution->isEmpty())
        const groupData = @json($groupDistribution);
        new Chart(document.getElementById('groupPieChart'), {
            type: 'doughnut',
            data: {
                labels: groupData.map(g => g.group),
                datasets: [{
                    data: groupData.map(g => g.total_amount),
                    backgroundColor: COLORS
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'right' },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.label}: ¥${ctx.raw.toFixed(4)}`
                        }
                    }
                }
            }
        });
        @endif

        // 日志分页
        let logsLoaded = false;
        let currentPage = 1;
        let pageSize = 20;
        const tokenName = @json($tokenName);

        async function loadLogs() {
            const tbody = document.getElementById('logsTableBody');
            tbody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">加载中...</td></tr>';

            try {
                const res = await fetch(`/admin/user/${encodeURIComponent(tokenName)}/logs?page=${currentPage}&pageSize=${pageSize}`);
                const data = await res.json();

                logsLoaded = true;
                document.getElementById('totalCount').textContent = data.total;
                document.getElementById('currentPage').textContent = data.page;
                document.getElementById('totalPages').textContent = data.totalPages;

                document.getElementById('prevBtn').disabled = data.page <= 1;
                document.getElementById('nextBtn').disabled = data.page >= data.totalPages;

                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">暂无数据</td></tr>';
                    return;
                }

                tbody.innerHTML = data.data.map(log => `
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">${log.created_at}</td>
                        <td class="px-4 py-3 text-gray-700">${log.group || '-'}</td>
                        <td class="px-4 py-3 text-gray-800 font-medium">${log.model_name}</td>
                        <td class="px-4 py-3 text-right text-gray-700">${log.prompt_tokens.toLocaleString()}</td>
                        <td class="px-4 py-3 text-right text-gray-700">${log.completion_tokens.toLocaleString()}</td>
                        <td class="px-4 py-3 text-right text-green-600 font-medium">¥${log.amount.toFixed(4)}</td>
                    </tr>
                `).join('');
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-red-500">加载失败</td></tr>';
            }
        }

        document.getElementById('prevBtn').addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                loadLogs();
            }
        });

        document.getElementById('nextBtn').addEventListener('click', () => {
            currentPage++;
            loadLogs();
        });

        document.getElementById('pageSize').addEventListener('change', (e) => {
            pageSize = parseInt(e.target.value);
            currentPage = 1;
            loadLogs();
        });
    </script>
</body>
</html>
