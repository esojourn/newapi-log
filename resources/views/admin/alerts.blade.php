<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>预警通知 - API Log</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        body { background-color: #E7F8FF; }
        .alz-nav { background: white; border-bottom: 2px solid #1D93AB; box-shadow: 0 1px 3px rgba(29,147,171,0.1); }
        .alz-thead { background-color: #f0fafc; color: #0f5a6b; }
        .alz-thead th { white-space: nowrap; }
        .alz-watch-table { min-width: 1080px; }
        .alz-tr:hover { background-color: #e8f7fc; }
        .alz-link { color: #1D93AB; }
        .alz-link:hover { color: #0f5a6b; }
        .alz-btn {
            background-color: #1D93AB; color: white; padding: 0.5rem 1.25rem;
            border-radius: 0.375rem; border: none; cursor: pointer; font-size: 0.875rem;
            transition: background-color 0.15s;
        }
        .alz-btn:hover { background-color: #177b8f; }
        .alz-btn-ghost {
            background: white; color: #1D93AB; border: 1px solid #1D93AB;
            padding: 0.5rem 1.25rem; border-radius: 0.375rem; cursor: pointer; font-size: 0.875rem;
        }
        .alz-btn-ghost:hover { background-color: #e8f7fc; }
        .alz-input { padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 0.375rem; }
        .alz-input:focus { outline: none; box-shadow: 0 0 0 2px #1D93AB; border-color: transparent; }
        input[type="checkbox"] { accent-color: #1D93AB; }
    </style>
</head>
<body class="min-h-screen">
    {{-- 顶部导航 --}}
    <nav class="alz-nav">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.dashboard') }}" class="text-gray-500 hover:text-gray-700" title="返回仪表盘">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <h1 class="text-xl font-bold text-gray-800">预警通知</h1>
            </div>
            <div class="flex items-center gap-4">
                <a href="{{ route('admin.channel-recovery') }}" class="alz-link text-sm">渠道恢复</a>
                <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-sm text-gray-500 hover:text-red-600 transition">登出</button>
                </form>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">
        @if (session('status'))
            <div class="bg-green-50 text-green-700 p-3 rounded text-sm">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="bg-red-50 text-red-600 p-3 rounded text-sm">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        {{-- ① 管理员全局设置 --}}
        <div class="bg-white rounded-lg shadow p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-1">管理员通知设置</h2>
            <p class="text-xs text-gray-400 mb-5">
                这套阈值与飞书地址只属于管理员，和用户各自在 /usage/alerts 里配的那套完全独立；
                同一个 Key 可以两边同时监控。余额持续低于阈值时每 {{ $remindHours }} 小时提醒一次；
                每小时超限预警在下方按 Key 单独启用，也使用这个飞书机器人。
            </p>

            <form method="POST" action="{{ route('admin.alerts.settings') }}" class="space-y-4">
                @csrf

                <label class="flex items-center gap-2">
                    <input type="checkbox" name="admin_enabled" value="1"
                        {{ old('admin_enabled', $adminEnabled) ? 'checked' : '' }}>
                    <span class="text-sm text-gray-700">启用管理员预警推送</span>
                </label>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label for="admin_webhook_url" class="block text-sm font-medium text-gray-700 mb-1">
                            飞书机器人 Webhook 地址
                        </label>
                        <input type="text" name="admin_webhook_url" id="admin_webhook_url" class="alz-input w-full"
                            placeholder="https://open.feishu.cn/open-apis/bot/v2/hook/..."
                            value="{{ old('admin_webhook_url', $adminWebhookUrl) }}">
                    </div>
                    <div>
                        <label for="admin_webhook_secret" class="block text-sm font-medium text-gray-700 mb-1">
                            签名校验密钥（可选）
                        </label>
                        <input type="password" name="admin_webhook_secret" id="admin_webhook_secret" class="alz-input w-full"
                            autocomplete="new-password"
                            value="{{ old('admin_webhook_secret', $adminWebhookSecret) }}">
                        <p class="text-xs text-gray-400 mt-1">机器人安全设置用「关键词」时留空，关键词填「余额预警」。</p>
                    </div>
                    <div>
                        <label for="admin_default_threshold_amount" class="block text-sm font-medium text-gray-700 mb-1">
                            默认预警金额（美元）
                        </label>
                        <input type="number" step="0.01" min="0" name="admin_default_threshold_amount"
                            id="admin_default_threshold_amount" class="alz-input w-full"
                            placeholder="{{ $defaultAmount }}"
                            value="{{ old('admin_default_threshold_amount', $adminDefaultAmount) }}">
                        <p class="text-xs text-gray-400 mt-1">下方没单独填阈值的 Key 用这个值。</p>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="alz-btn">保存设置</button>
                </div>
            </form>

            <div class="border-t mt-6 pt-4 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('admin.alerts.test') }}">
                    @csrf
                    <button type="submit" class="alz-btn-ghost">发送测试消息</button>
                </form>
                <form method="POST" action="{{ route('admin.alerts.run') }}">
                    @csrf
                    <button type="submit" class="alz-btn-ghost">立即检查一次</button>
                </form>
                <span class="text-xs text-gray-400">
                    按已保存的配置检查余额和前一小时消费，并发送符合条件的预警。
                </span>
            </div>
        </div>

        {{-- ② 已监控名单 --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-5 py-4 border-b">
                <h2 class="text-lg font-semibold text-gray-800">已监控的 Key（{{ $watches->count() }}）</h2>
                <p class="text-xs text-gray-400 mt-1">取消勾选并保存即可移出监控；余额预警金额留空表示沿用上面的默认金额。</p>
                <p class="text-xs text-gray-400 mt-1">
                    每小时超限预警需单独启用并填写美元限额，统计上一完整自然小时（例如 14:00 检查 13:00–14:00，时区 {{ config('app.timezone') }}）。
                    消费超过限额时发送飞书通知，同一小时成功发送后不重复提醒，无限额度 Key 也适用。
                </p>
                <p class="text-xs text-gray-400 mt-1 md:hidden">左右滑动表格可查看每小时预警设置与推送状态。</p>
            </div>

            @if ($watches->isEmpty())
                <div class="px-5 py-8 text-sm text-gray-400 text-center">还没有勾选任何 Key，在下方列表里挑选。</div>
            @else
                <form method="POST" action="{{ route('admin.alerts.watches') }}">
                    @csrf
                    <input type="hidden" name="hourly_settings_present" value="1">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm alz-watch-table">
                            <thead class="alz-thead">
                                <tr>
                                    <th class="px-4 py-3 text-left w-12">监控</th>
                                    <th class="px-4 py-3 text-left">Key 名称</th>
                                    <th class="px-4 py-3 text-right">当前余额</th>
                                    <th class="px-4 py-3 text-left w-40">余额预警金额（$）</th>
                                    <th class="px-4 py-3 text-left w-56">每小时超限预警</th>
                                    <th class="px-4 py-3 text-left">余额上次推送</th>
                                    <th class="px-4 py-3 text-left">超限上次推送</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach ($watches as $watch)
                                    @php $t = $watchedTokens->get($watch->token_id); @endphp
                                    <tr class="alz-tr">
                                        <td class="px-4 py-3">
                                            <input type="checkbox" name="watch[]" value="{{ $watch->token_id }}" checked>
                                            <input type="hidden" name="page_token_ids[]" value="{{ $watch->token_id }}">
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <a href="{{ route('admin.user.detail', ['tokenName' => $watch->token_name]) }}"
                                                class="alz-link">{{ $watch->token_name }}</a>
                                            @if (!$t)
                                                <span class="ml-2 text-xs text-red-500">Key 已不存在</span>
                                            @elseif ($t->unlimited_quota)
                                                <span class="ml-2 text-xs text-yellow-600">无限额度，不做余额预警</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            {{ $t && !$t->unlimited_quota ? \App\Support\Quota::format((int) $t->remain_quota) : '-' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <input type="number" step="0.01" min="0"
                                                name="threshold[{{ $watch->token_id }}]" class="alz-input w-32"
                                                placeholder="{{ $adminDefaultAmount ?? $defaultAmount }}"
                                                value="{{ old('threshold.' . $watch->token_id, $watchAmounts[$watch->token_id] ?? '') }}">
                                        </td>
                                        <td class="px-4 py-3">
                                            @php
                                                $hourlyEnabled = old('hourly_settings_present')
                                                    ? in_array($watch->token_id, (array) old('hourly_enabled', []))
                                                    : $watch->hourly_alert_enabled;
                                            @endphp
                                            <label class="flex items-center gap-2 mb-2 whitespace-nowrap">
                                                <input type="checkbox" name="hourly_enabled[]" value="{{ $watch->token_id }}"
                                                    {{ $hourlyEnabled ? 'checked' : '' }}>
                                                <span class="text-xs text-gray-600">启用每小时超限预警</span>
                                            </label>
                                            <label for="hourly_threshold_{{ $watch->token_id }}" class="text-xs text-gray-500">限额（$）</label>
                                            <input type="number" step="0.01" min="0" max="100000"
                                                id="hourly_threshold_{{ $watch->token_id }}"
                                                name="hourly_threshold[{{ $watch->token_id }}]" class="alz-input w-32"
                                                placeholder="手工填写限额"
                                                value="{{ old('hourly_threshold.' . $watch->token_id, $hourlyWatchAmounts[$watch->token_id] ?? '') }}">
                                        </td>
                                        <td class="px-4 py-3 text-gray-500">
                                            @if ($watch->last_notified_at)
                                                {{ $watch->last_notified_at->format('m-d H:i') }}
                                            @else
                                                <span class="text-gray-300">未推送</span>
                                            @endif
                                            @if ($watch->last_error)
                                                <div class="text-xs text-red-500">{{ $watch->last_error }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-500">
                                            @if ($watch->last_hourly_notified_at)
                                                {{ $watch->last_hourly_notified_at->format('m-d H:i') }}
                                                <div class="text-xs">消费 {{ \App\Support\Quota::format((int) $watch->last_hourly_notified_quota) }}</div>
                                            @else
                                                <span class="text-gray-300">未推送</span>
                                            @endif
                                            @if ($watch->last_hourly_error)
                                                <div class="text-xs text-red-500">{{ $watch->last_hourly_error }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="px-5 py-4 border-t">
                        <button type="submit" class="alz-btn">保存监控名单</button>
                    </div>
                </form>
            @endif
        </div>

        {{-- ③ 选择要监控的 Key --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-5 py-4 border-b flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">选择要监控的 Key</h2>
                    <p class="text-xs text-gray-400 mt-1">
                        勾选后保存即加入监控。列表是分页的，保存只影响<strong>当前这一页</strong>的勾选状态。
                    </p>
                </div>
                <form method="GET" action="{{ route('admin.alerts') }}" class="flex items-center gap-2">
                    <input type="text" name="q" class="alz-input" placeholder="按 Key 名称搜索" value="{{ $keyword }}">
                    <button type="submit" class="alz-btn-ghost">搜索</button>
                    @if ($keyword !== '')
                        <a href="{{ route('admin.alerts') }}" class="text-sm text-gray-500 hover:text-gray-700">清除</a>
                    @endif
                </form>
            </div>

            @if ($tokens->isEmpty())
                <div class="px-5 py-8 text-sm text-gray-400 text-center">没有匹配的 Key。</div>
            @else
                <form method="POST" action="{{ route('admin.alerts.watches') }}">
                    @csrf
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="alz-thead">
                                <tr>
                                    <th class="px-4 py-3 text-left w-12">
                                        <input type="checkbox" id="checkAll" title="全选本页">
                                    </th>
                                    <th class="px-4 py-3 text-left">Key 名称</th>
                                    <th class="px-4 py-3 text-right">当前余额</th>
                                    <th class="px-4 py-3 text-left w-40">预警金额（$）</th>
                                    <th class="px-4 py-3 text-left">用户自己的订阅</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach ($tokens as $token)
                                    <tr class="alz-tr">
                                        <td class="px-4 py-3">
                                            <input type="checkbox" class="row-check" name="watch[]" value="{{ $token->id }}"
                                                {{ $watchedIds->has($token->id) ? 'checked' : '' }}>
                                            <input type="hidden" name="page_token_ids[]" value="{{ $token->id }}">
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <a href="{{ route('admin.user.detail', ['tokenName' => $token->name]) }}"
                                                class="alz-link">{{ $token->name }}</a>
                                            @if ($token->unlimited_quota)
                                                <span class="ml-2 text-xs text-yellow-600">无限额度，不做余额预警</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            {{ $token->unlimited_quota ? '-' : \App\Support\Quota::format((int) $token->remain_quota) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <input type="number" step="0.01" min="0"
                                                name="threshold[{{ $token->id }}]" class="alz-input w-32"
                                                placeholder="{{ $adminDefaultAmount ?? $defaultAmount }}"
                                                value="{{ $watchAmounts[$token->id] ?? '' }}">
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($subscribedIds->has($token->id))
                                                <span class="text-xs text-green-600">已开启</span>
                                            @else
                                                <span class="text-xs text-gray-300">未开启</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="px-5 py-4 border-t flex flex-wrap items-center justify-between gap-3">
                        <button type="submit" class="alz-btn">保存本页勾选</button>
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-gray-400">
                                第 {{ $tokens->currentPage() }} / {{ $tokens->lastPage() }} 页，共 {{ number_format($tokens->total()) }} 个 Key
                            </span>
                            @if ($tokens->previousPageUrl())
                                <a href="{{ $tokens->previousPageUrl() }}" class="alz-link">上一页</a>
                            @else
                                <span class="text-gray-300">上一页</span>
                            @endif
                            @if ($tokens->nextPageUrl())
                                <a href="{{ $tokens->nextPageUrl() }}" class="alz-link">下一页</a>
                            @else
                                <span class="text-gray-300">下一页</span>
                            @endif
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <script>
        // 「全选本页」只操作当前页渲染出来的行，与后端只处理 page_token_ids 的口径一致
        var checkAll = document.getElementById('checkAll');
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                document.querySelectorAll('.row-check').forEach(function (box) {
                    box.checked = checkAll.checked;
                });
            });
        }
    </script>
</body>
</html>
