<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通知设置 - {{ $tokenName }}</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        body { background-color: #ffffff; }
        .alz-nav { background: white; border-bottom: 2px solid #1D93AB; box-shadow: 0 1px 3px rgba(29,147,171,0.1); }
        .alz-btn {
            background-color: #1D93AB; color: white; padding: 0.5rem 1.25rem;
            border-radius: 0.375rem; border: none; cursor: pointer; font-size: 0.875rem;
            transition: background-color 0.15s;
        }
        .alz-btn:hover { background-color: #177b8f; }
        .alz-btn:disabled { background-color: #9ca3af; cursor: not-allowed; }
        .alz-btn-ghost {
            background: white; color: #1D93AB; border: 1px solid #1D93AB;
            padding: 0.5rem 1.25rem; border-radius: 0.375rem; cursor: pointer; font-size: 0.875rem;
        }
        .alz-btn-ghost:hover { background-color: #e8f7fc; }
        .alz-input {
            width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem;
        }
        .alz-input:focus { outline: none; box-shadow: 0 0 0 2px #1D93AB; border-color: transparent; }
        .alz-input:disabled { background-color: #f3f4f6; color: #9ca3af; }
        .alz-card { background: white; border-top: 3px solid #1D93AB; border-radius: 0.5rem; box-shadow: 0 4px 16px rgba(29,147,171,0.12); }
    </style>
</head>
<body class="min-h-screen">
    {{-- 顶部导航 --}}
    <nav class="alz-nav">
        <div class="max-w-3xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-3">
                <a href="{{ route('user.usage') }}" class="text-gray-500 hover:text-gray-700" title="返回用量页">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <h1 class="text-xl font-bold text-gray-800">通知设置</h1>
            </div>
            <form method="POST" action="{{ route('user.signout') }}" class="inline">
                @csrf
                <button type="submit" class="text-sm text-gray-500 hover:text-red-600 transition">切换 Key</button>
            </form>
        </div>
    </nav>

    <div class="max-w-3xl mx-auto px-4 py-6 space-y-5">
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

        {{-- 当前状态 --}}
        <div class="alz-card p-5">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-sm text-gray-500">API Key</div>
                    <div class="text-lg font-semibold text-gray-800 mt-1 break-all">{{ $tokenName }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">当前余额</div>
                    <div class="text-lg font-bold mt-1" style="color:#1D93AB;">{{ $balance }}</div>
                </div>
            </div>

            @if ($subscription && $subscription->last_notified_at)
                <div class="text-xs text-gray-400 mt-4">
                    上次推送：{{ $subscription->last_notified_at->format('Y-m-d H:i') }}
                    （当时余额 {{ \App\Support\Quota::format($subscription->last_notified_quota) }}）
                </div>
            @endif

            @if ($subscription && $subscription->last_error)
                <div class="text-xs text-red-500 mt-2">
                    上次推送失败（{{ optional($subscription->last_error_at)->format('Y-m-d H:i') }}）：{{ $subscription->last_error }}
                </div>
            @endif
        </div>

        @if ($unlimited)
            <div class="bg-yellow-50 text-yellow-800 p-4 rounded text-sm">
                该 Key 为<strong>无限额度</strong>，没有可用于比较的剩余额度，因此不做余额预警。
                若需要预警，请在 NewAPI 后台给这个 Key 设置额度上限。
            </div>
        @endif

        {{-- 设置表单 --}}
        <div class="alz-card p-5">
            <h2 class="text-lg font-semibold text-gray-800 mb-1">余额预警</h2>
            <p class="text-xs text-gray-400 mb-5">
                余额低于设定金额时，通过你自己的飞书群机器人推送提醒；持续低于阈值时每 {{ $remindHours }} 小时提醒一次，充值后自动恢复。
            </p>

            <form method="POST" action="{{ route('user.alerts.save') }}" class="space-y-4">
                @csrf

                <label class="flex items-center gap-2 {{ $unlimited ? 'opacity-50' : '' }}">
                    <input type="checkbox" name="enabled" value="1" style="accent-color:#1D93AB;"
                        {{ old('enabled', $subscription->enabled ?? false) ? 'checked' : '' }}
                        {{ $unlimited ? 'disabled' : '' }}>
                    <span class="text-sm text-gray-700">启用余额预警</span>
                </label>

                <div>
                    <label for="threshold_amount" class="block text-sm font-medium text-gray-700 mb-1">
                        预警金额（美元）
                    </label>
                    <input type="number" step="0.01" min="0" name="threshold_amount" id="threshold_amount"
                        class="alz-input" placeholder="{{ $defaultAmount }}"
                        value="{{ old('threshold_amount', $thresholdAmount) }}"
                        {{ $unlimited ? 'disabled' : '' }}>
                    <p class="text-xs text-gray-400 mt-1">余额低于这个数字时触发推送。</p>
                </div>

                <div>
                    <label for="webhook_url" class="block text-sm font-medium text-gray-700 mb-1">
                        飞书机器人 Webhook 地址
                    </label>
                    <input type="text" name="webhook_url" id="webhook_url" class="alz-input"
                        placeholder="https://open.feishu.cn/open-apis/bot/v2/hook/..."
                        value="{{ old('webhook_url', $subscription->webhook_url ?? '') }}"
                        {{ $unlimited ? 'disabled' : '' }}>
                    <p class="text-xs text-gray-400 mt-1">
                        在飞书群里「设置 → 群机器人 → 添加机器人 → 自定义机器人」获取，只接受 open.feishu.cn / open.larksuite.com 的地址。
                    </p>
                </div>

                <div>
                    <label for="webhook_secret" class="block text-sm font-medium text-gray-700 mb-1">
                        签名校验密钥（可选）
                    </label>
                    <input type="password" name="webhook_secret" id="webhook_secret" class="alz-input"
                        autocomplete="new-password"
                        value="{{ old('webhook_secret', $subscription->webhook_secret ?? '') }}"
                        {{ $unlimited ? 'disabled' : '' }}>
                    <p class="text-xs text-gray-400 mt-1">
                        机器人安全设置选「签名校验」时填这里；选「关键词」的话把关键词设为「余额预警」，这一项留空。
                    </p>
                </div>

                <div class="pt-2">
                    <button type="submit" class="alz-btn" {{ $unlimited ? 'disabled' : '' }}>保存设置</button>
                </div>
            </form>

            <div class="border-t mt-6 pt-4 flex items-center gap-3">
                <form method="POST" action="{{ route('user.alerts.test') }}">
                    @csrf
                    <button type="submit" class="alz-btn-ghost">发送测试消息</button>
                </form>
                <span class="text-xs text-gray-400">使用已保存的配置发送，修改后请先保存。</span>
            </div>
        </div>
    </div>
</body>
</html>
