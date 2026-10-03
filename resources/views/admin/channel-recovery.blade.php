<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>渠道自动恢复 - API Log</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        body { background-color: #E7F8FF; }
        .alz-nav { background: white; border-bottom: 2px solid #1D93AB; box-shadow: 0 1px 3px rgba(29,147,171,0.1); }
        .alz-link { color: #1D93AB; }
        .alz-link:hover { color: #0f5a6b; }
        .alz-btn { background: #1D93AB; color: white; padding: .5rem 1.25rem; border-radius: .375rem; font-size: .875rem; }
        .alz-btn:hover { background: #177b8f; }
        .alz-input { padding: .5rem .65rem; border: 1px solid #d1d5db; border-radius: .375rem; background: white; min-width: 0; }
        .alz-input:focus { outline: none; box-shadow: 0 0 0 2px #1D93AB; border-color: transparent; }
        .alz-thead { background: #f0fafc; color: #0f5a6b; }
        .alz-tr:hover { background: #f5fbfd; }
        .alz-log-table { table-layout: fixed; }
        .alz-log-table th, .alz-log-table td { padding: .625rem .75rem; vertical-align: top; }
        .alz-log-detail summary { position: relative; padding-right: 1rem; cursor: pointer; list-style: none; border-radius: .125rem; }
        .alz-log-detail summary::-webkit-details-marker { display: none; }
        .alz-log-detail summary::after { content: '+'; position: absolute; top: 0; right: 0; color: #1D93AB; }
        .alz-log-detail[open] summary::after { content: '−'; }
        .alz-log-detail summary:focus-visible { outline: 2px solid #1D93AB; outline-offset: 2px; }
        .alz-log-preview { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; max-height: 2.5rem; overflow: hidden; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.25rem; }
        .alz-log-preview-single { -webkit-line-clamp: 1; max-height: 1.25rem; }
        .alz-log-detail[open] .alz-log-preview { display: block; max-height: none; }
        input[type="checkbox"] { accent-color: #1D93AB; }
    </style>
</head>
<body class="min-h-screen text-gray-800">
    <nav class="alz-nav" aria-label="管理员导航">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold">渠道自动恢复</h1>
            <div class="flex items-center gap-4 text-sm">
                <a href="{{ route('admin.dashboard') }}" class="text-gray-500 hover:text-gray-700">统计仪表盘</a>
                <a href="{{ route('admin.alerts') }}" class="text-gray-500 hover:text-gray-700">预警通知</a>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-gray-500 hover:text-red-600">登出</button>
                </form>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 py-6 space-y-6">
        @if (session('status'))
            <div role="status" class="bg-green-50 text-green-800 p-4 rounded-lg text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="bg-red-50 text-red-700 p-4 rounded-lg text-sm">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        @if ($tokenUnreadable)
            <div role="alert" class="bg-yellow-50 text-yellow-800 p-4 rounded-lg text-sm">已保存的访问令牌无法读取，请重新填写后保存。</div>
        @endif

        <section class="bg-white rounded-lg shadow p-5 md:p-6" aria-labelledby="last-run-heading">
            <h2 id="last-run-heading" class="text-lg font-semibold">最近一轮检查</h2>
            @if (!$lastRun)
                <p class="text-sm text-gray-500 mt-2">尚无检查记录。开启自动恢复且检查计划到期后，这里会显示每轮结果。</p>
            @else
                @php
                    $runUnfinished = $lastRun->finished_at === null;
                    $runStale = $runUnfinished && $lastRun->started_at->lt(now()->subHour());
                    $stageLabels = ['read' => '读取渠道详情', 'monitor' => '写入监控日志', 'test' => '渠道测试', 'audit' => '写入恢复日志', 'enable' => '恢复启用'];
                @endphp
                <div role="{{ $lastRun->hasProblems() || $runStale ? 'alert' : 'status' }}" class="mt-3 p-4 rounded-lg text-sm {{ $lastRun->error !== null || $runStale ? 'bg-red-50 text-red-700' : ($lastRun->failed > 0 ? 'bg-yellow-50 text-yellow-800' : ($runUnfinished ? 'bg-gray-50 text-gray-700' : 'bg-green-50 text-green-800')) }}">
                    <p>
                        <time datetime="{{ $lastRun->started_at->toIso8601String() }}">{{ $lastRun->started_at->format('Y-m-d H:i:s') }}</time>
                        · {{ $lastRun->source === 'scheduled' ? '定时任务' : '手动命令' }}{{ $lastRun->dry_run ? '（试运行）' : '' }}
                        · @if ($runStale) 未完成，进程可能已中断 @elseif ($runUnfinished) 进行中 @else 检查 {{ $lastRun->checked }}，测试正常 {{ $lastRun->healthy }}，已恢复 {{ $lastRun->recovered }}，跳过 {{ $lastRun->skipped }}，失败 {{ $lastRun->failed }} @endif
                    </p>
                    @if ($lastRun->error !== null)
                        <p class="mt-2 font-medium whitespace-pre-wrap break-all">本轮未能完成：{{ $lastRun->error }}</p>
                    @endif
                    @if (!empty($lastRun->failures))
                        <ul class="mt-2 space-y-1">
                            @foreach ($lastRun->failures as $failure)
                                <li class="whitespace-pre-wrap break-all">#{{ $failure['channel_id'] }} · {{ $stageLabels[$failure['stage']] ?? $failure['stage'] }}：{{ $failure['message'] }}</li>
                            @endforeach
                        </ul>
                        @if ($lastRun->failed > count($lastRun->failures))
                            <p class="mt-1 text-xs">仅显示前 {{ count($lastRun->failures) }} 条，完整记录见服务日志。</p>
                        @endif
                    @endif
                </div>
                <p class="text-xs text-gray-500 mt-2">读取渠道详情失败时不会生成监控日志，请以这里的提示为准；常见原因是 NewAPI 地址、访问令牌或用户 ID 配置错误。</p>
            @endif
        </section>

        <section class="bg-white rounded-lg shadow p-5 md:p-6" aria-labelledby="settings-heading">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
                <div>
                    <h2 id="settings-heading" class="text-lg font-semibold">恢复设置</h2>
                    <p class="text-sm text-gray-500 mt-1">仅检查自动封禁已开启、状态为「自动禁用」的渠道。测试正常后恢复为「启用」。</p>
                </div>
                <span class="text-xs font-medium px-3 py-1 rounded-full {{ $settings['enabled'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                    {{ $settings['enabled'] ? '自动恢复已开启' : '自动恢复已关闭' }}
                </span>
            </div>

            <form method="POST" action="{{ route('admin.channel-recovery.save') }}" class="space-y-5">
                @csrf
                <input type="hidden" name="enabled" value="0">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" name="enabled" value="1" {{ old('enabled', $settings['enabled']) ? 'checked' : '' }}>
                    开启渠道自动恢复
                </label>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label for="schedule_cron" class="block text-sm font-medium mb-1">检查计划</label>
                        <input id="schedule_cron" name="schedule_cron" list="cron-presets" required maxlength="100"
                            class="alz-input w-full font-mono text-sm" value="{{ old('schedule_cron', $settings['schedule_cron']) }}" aria-describedby="schedule-help">
                        <datalist id="cron-presets">
                            <option value="* * * * *">每分钟</option>
                            <option value="*/5 * * * *">每 5 分钟</option>
                            <option value="*/10 * * * *">每 10 分钟</option>
                            <option value="*/15 * * * *">每 15 分钟</option>
                            <option value="*/30 * * * *">每 30 分钟</option>
                            <option value="0 * * * *">每小时</option>
                        </datalist>
                        <p id="schedule-help" class="text-xs text-gray-500 mt-2">选择常用计划或填写 Cron 表达式。<code>*/5 * * * *</code> 为每 5 分钟，时区 {{ config('app.timezone') }}。</p>
                    </div>
                    <div>
                        <label for="http_timeout" class="block text-sm font-medium mb-1">请求超时（秒）</label>
                        <input type="number" id="http_timeout" name="http_timeout" required min="1" max="300" step="1"
                            class="alz-input w-full text-sm" value="{{ old('http_timeout', $settings['http_timeout']) }}">
                        <p class="text-xs text-gray-500 mt-2">每次请求最多等待 1–300 秒；检测超时的渠道保留原状态。</p>
                    </div>
                    <div class="md:col-span-2">
                        <label for="base_url" class="block text-sm font-medium mb-1">NewAPI 地址</label>
                        <input type="url" id="base_url" name="base_url" maxlength="500" class="alz-input w-full text-sm"
                            placeholder="https://your-newapi.example" value="{{ old('base_url', $settings['base_url']) }}" aria-describedby="url-help">
                        <p id="url-help" class="text-xs text-gray-500 mt-2">填写当前数据库对应的 NewAPI 站点地址，不加 /v1 或 /api/channel。</p>
                    </div>
                    <div>
                        <label for="user_id" class="block text-sm font-medium mb-1">NewAPI 管理员用户 ID</label>
                        <input type="number" id="user_id" name="user_id" min="1" step="1" class="alz-input w-full text-sm"
                            placeholder="例如 1" value="{{ old('user_id', $settings['user_id']) }}">
                        <p class="text-xs text-gray-500 mt-2">填写访问令牌所属管理员的数字 ID。</p>
                    </div>
                    <div>
                        <label for="access_token" class="block text-sm font-medium mb-1">管理员访问令牌</label>
                        <input type="password" id="access_token" name="access_token" maxlength="4096" autocomplete="new-password"
                            class="alz-input w-full text-sm" placeholder="{{ $tokenConfigured ? '已配置，留空保留原令牌' : '填写管理员个人访问令牌' }}" aria-describedby="token-help">
                        <p id="token-help" class="text-xs text-gray-500 mt-2">加密保存，不回显。请使用管理员访问令牌，而非模型调用的 sk-… 密钥。</p>
                        @if ($tokenConfigured || $tokenUnreadable)
                            <label class="inline-flex items-center gap-2 text-xs text-gray-600 mt-2">
                                <input type="checkbox" name="clear_access_token" value="1" {{ old('clear_access_token') ? 'checked' : '' }}>
                                清除已保存的令牌（需关闭自动恢复）
                            </label>
                        @endif
                    </div>
                </div>

                <div class="border-t pt-4 flex flex-wrap items-center gap-3">
                    <button type="submit" class="alz-btn">保存设置</button>
                    <p class="text-xs text-gray-500">{{ $saved ? '页面设置已生效；保存后用于下次调度。' : '当前沿用环境配置；首次保存后以此页面为准。' }}服务器需已启用定时调度。</p>
                </div>
            </form>
        </section>

        <section class="bg-white rounded-lg shadow overflow-hidden" aria-labelledby="monitor-heading">
            <div class="p-5 md:p-6 border-b space-y-4">
                <div>
                    <h2 id="monitor-heading" class="text-lg font-semibold">监控日志 <span class="text-sm font-normal text-gray-500">（{{ $monitorLogs->total() }} 条）</span></h2>
                    <p class="text-xs text-gray-500 mt-1">每轮检查记录发现的自动禁用渠道，保留禁用原因和本次检测提示。试运行也会记录；测试正常后的恢复结果见下方恢复动作日志。时间均为 {{ config('app.timezone') }}。</p>
                    <p class="text-xs text-gray-500 mt-1">长内容最多显示两行；悬停查看全文，点击 + 展开并复制，再次点击收起。</p>
                </div>
                <form method="GET" action="{{ route('admin.channel-recovery') }}#monitor-heading" class="flex flex-wrap items-end gap-3">
                    @foreach (['channel_id', 'result'] as $filter)
                        @if (!empty($filters[$filter]))
                            <input type="hidden" name="{{ $filter }}" value="{{ $filters[$filter] }}">
                        @endif
                    @endforeach
                    <div>
                        <label for="monitor-channel-id" class="block text-xs text-gray-600 mb-1">渠道 ID</label>
                        <input type="number" min="1" step="1" id="monitor-channel-id" name="monitor_channel_id" class="alz-input text-sm w-32" placeholder="全部渠道" value="{{ $filters['monitor_channel_id'] ?? '' }}">
                    </div>
                    <div>
                        <label for="monitor-result" class="block text-xs text-gray-600 mb-1">检测结果</label>
                        <select id="monitor-result" name="monitor_result" class="alz-input text-sm">
                            <option value="">全部结果</option>
                            @foreach ($monitorResults as $value => $label)
                                <option value="{{ $value }}" {{ ($filters['monitor_result'] ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="alz-btn">筛选监控日志</button>
                    @if (!empty($filters['monitor_channel_id']) || !empty($filters['monitor_result']))
                        <a href="{{ route('admin.channel-recovery', array_intersect_key($filters, array_flip(['channel_id', 'result']))) }}#monitor-heading" class="alz-link text-sm py-2">清除筛选</a>
                    @endif
                </form>
            </div>
            @if ($monitorLogs->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-gray-500">{{ !empty($filters['monitor_channel_id']) || !empty($filters['monitor_result']) ? '没有符合条件的监控日志。' : '暂无监控日志。开启自动恢复后，检查发现自动禁用渠道时会记录在这里。' }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="alz-log-table w-full text-sm" style="min-width: 1100px;">
                        <colgroup>
                            <col style="width: 205px;">
                            <col style="width: 150px;">
                            <col style="width: 100px;">
                            <col>
                            <col style="width: 100px;">
                            <col>
                        </colgroup>
                        <thead class="alz-thead">
                            <tr>
                                <th scope="col" class="text-left px-5 py-3 font-medium">发现时间 / 禁用时间</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">渠道</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">触发方式</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">自动禁用原因</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">检测结果</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">本次检测提示</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($monitorLogs as $log)
                                <tr class="alz-tr align-top">
                                    <td class="px-5 py-4 whitespace-nowrap text-gray-600">
                                        <time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('Y-m-d H:i:s') }}</time>
                                        <div class="text-xs text-gray-400 mt-1">禁用 {{ $log->disabled_at ? $log->disabled_at->format('Y-m-d H:i:s') : '时间未提供' }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-medium"><x-compact-log-text :text="$log->channel_name ?: '未命名渠道'" :lines="1" /></div>
                                        <div class="text-xs text-gray-500 mt-1">#{{ $log->channel_id }} · 自动禁用</div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap text-gray-600">
                                        {{ $log->source === 'scheduled' ? '定时任务' : '手动命令' }}
                                        @if ($log->dry_run)
                                            <div class="text-xs text-gray-500 mt-1">试运行</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-gray-600">
                                        <x-compact-log-text :text="$log->disabled_reason ?? '上游未提供禁用原因。'" />
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 rounded-full text-xs {{ ['healthy' => 'bg-green-100 text-green-800', 'failed' => 'bg-red-100 text-red-700', 'error' => 'bg-red-100 text-red-700', 'pending' => 'bg-yellow-100 text-yellow-800'][$log->result] ?? 'bg-gray-100 text-gray-600' }}">{{ $monitorResults[$log->result] ?? $log->result }}</span>
                                    </td>
                                    <td class="px-5 py-4 text-gray-600">
                                        <x-compact-log-text :text="$log->message ?? '等待检测结果；长时间未完成请检查任务运行情况。'" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-4 border-t">{{ $monitorLogs->fragment('monitor-heading')->links() }}</div>
            @endif
        </section>

        <section class="bg-white rounded-lg shadow overflow-hidden" aria-labelledby="logs-heading">
            <div class="p-5 md:p-6 border-b space-y-4">
                <div>
                    <h2 id="logs-heading" class="text-lg font-semibold">恢复动作日志 <span class="text-sm font-normal text-gray-500">（{{ $logs->total() }} 条）</span></h2>
                    <p class="text-xs text-gray-500 mt-1">记录测试通过后的恢复尝试。试运行和未通过的检测不会产生恢复动作；未完成或未确认的记录需核对渠道实际状态。</p>
                    <p class="text-xs text-gray-500 mt-1">长内容最多显示两行；悬停查看全文，点击 + 展开并复制，再次点击收起。</p>
                </div>
                <form method="GET" action="{{ route('admin.channel-recovery') }}" class="flex flex-wrap items-end gap-3">
                    @foreach (['monitor_channel_id', 'monitor_result'] as $filter)
                        @if (!empty($filters[$filter]))
                            <input type="hidden" name="{{ $filter }}" value="{{ $filters[$filter] }}">
                        @endif
                    @endforeach
                    <div>
                        <label for="log-channel-id" class="block text-xs text-gray-600 mb-1">渠道 ID</label>
                        <input type="number" min="1" step="1" id="log-channel-id" name="channel_id" class="alz-input text-sm w-32" placeholder="全部渠道" value="{{ $filters['channel_id'] ?? '' }}">
                    </div>
                    <div>
                        <label for="log-result" class="block text-xs text-gray-600 mb-1">恢复结果</label>
                        <select id="log-result" name="result" class="alz-input text-sm">
                            <option value="">全部结果</option>
                            @foreach ($results as $value => $label)
                                <option value="{{ $value }}" {{ ($filters['result'] ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="alz-btn">筛选日志</button>
                    @if (!empty($filters['channel_id']) || !empty($filters['result']))
                        <a href="{{ route('admin.channel-recovery', array_intersect_key($filters, array_flip(['monitor_channel_id', 'monitor_result']))) }}#logs-heading" class="alz-link text-sm py-2">清除筛选</a>
                    @endif
                </form>
            </div>
            @if ($logs->isEmpty())
                <div class="px-5 py-12 text-center text-sm text-gray-500">{{ !empty($filters['channel_id']) || !empty($filters['result']) ? '没有符合条件的恢复日志。' : '暂无恢复动作。渠道测试正常并尝试恢复后，记录会显示在这里。' }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="alz-log-table w-full text-sm" style="min-width: 1000px;">
                        <colgroup>
                            <col style="width: 205px;">
                            <col style="width: 150px;">
                            <col style="width: 100px;">
                            <col style="width: 155px;">
                            <col style="width: 100px;">
                            <col>
                        </colgroup>
                        <thead class="alz-thead">
                            <tr>
                                <th scope="col" class="text-left px-5 py-3 font-medium">发起时间</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium whitespace-nowrap">渠道</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">触发方式</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">恢复动作</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">结果</th>
                                <th scope="col" class="text-left px-5 py-3 font-medium">说明</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($logs as $log)
                                <tr class="alz-tr">
                                    <td class="px-5 py-4 whitespace-nowrap text-gray-600">
                                        <time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('Y-m-d H:i:s') }}</time>
                                        @if ($log->completed_at)
                                            <div class="text-xs text-gray-400 mt-1">完成 {{ $log->completed_at->format('Y-m-d H:i:s') }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-medium"><x-compact-log-text :text="$log->channel_name ?: '未命名渠道'" :lines="1" /></div>
                                        <div class="text-xs text-gray-500 mt-1">#{{ $log->channel_id }}</div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap text-gray-600">{{ $log->source === 'scheduled' ? '定时任务' : '手动命令' }}</td>
                                    <td class="px-5 py-4 whitespace-nowrap text-gray-600">自动禁用 → 启用</td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 rounded-full text-xs {{ ['recovered' => 'bg-green-100 text-green-800', 'skipped' => 'bg-gray-100 text-gray-600', 'failed' => 'bg-red-100 text-red-700', 'pending' => 'bg-yellow-100 text-yellow-800'][$log->result] ?? 'bg-gray-100 text-gray-600' }}">{{ $results[$log->result] ?? $log->result }}</span>
                                    </td>
                                    <td class="px-5 py-4 text-gray-600"><x-compact-log-text :text="$log->message ?? ''" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-4 border-t">{{ $logs->links() }}</div>
            @endif
        </section>
    </main>
</body>
</html>
