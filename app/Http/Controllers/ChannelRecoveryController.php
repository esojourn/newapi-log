<?php

namespace App\Http\Controllers;

use App\Models\ChannelMonitorLog;
use App\Models\ChannelRecoveryLog;
use App\Models\ChannelRecoveryRun;
use App\Models\ChannelRecoverySetting;
use App\Services\ChannelLogGroups;
use App\Services\NewApiChannelClient;
use Cron\CronExpression;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** 所有路由都在 admin 中间件内；普通 Key 登录不能访问设置或日志。 */
class ChannelRecoveryController extends Controller
{
    public function index(Request $request, ChannelLogGroups $logGroups)
    {
        $filters = $request->validate([
            'channel_id' => 'nullable|integer|min:1',
            'result' => ['nullable', Rule::in(array_keys(ChannelRecoveryLog::RESULTS))],
            'monitor_channel_id' => 'nullable|integer|min:1',
            'monitor_result' => ['nullable', Rule::in(array_keys(ChannelMonitorLog::RESULTS))],
        ]);
        $settings = ChannelRecoverySetting::current();
        $logQuery = ChannelRecoveryLog::query()
            ->when($filters['channel_id'] ?? null, fn ($query, $id) => $query->where('channel_id', $id))
            ->when($filters['result'] ?? null, fn ($query, $result) => $query->where('result', $result));
        $monitorQuery = ChannelMonitorLog::query()
            ->when($filters['monitor_channel_id'] ?? null, fn ($query, $id) => $query->where('channel_id', $id))
            ->when($filters['monitor_result'] ?? null, fn ($query, $result) => $query->where('result', $result));
        $logs = $logGroups->paginate($logQuery, [
            'channel_id', 'result', 'message', 'source', 'from_status', 'target_status',
        ]);
        $monitorLogs = $logGroups->paginate($monitorQuery, [
            'channel_id', 'result', 'disabled_reason', 'message', 'source', 'dry_run',
        ], 'monitor_page');
        $token = $settings->access_token;

        return response()->view('admin.channel-recovery', [
            'settings' => $settings->toArray(),
            'saved' => $settings->exists,
            'tokenConfigured' => $token !== null && $token !== '',
            'tokenUnreadable' => !empty($settings->getAttributes()['access_token']) && $token === null,
            'logs' => $logs,
            'logCount' => $logQuery->count(),
            'filters' => $filters,
            'results' => ChannelRecoveryLog::RESULTS,
            'monitorLogs' => $monitorLogs,
            'monitorLogCount' => $monitorQuery->count(),
            'monitorResults' => ChannelMonitorLog::RESULTS,
            'lastRun' => ChannelRecoveryRun::current(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'schedule_cron' => ['bail', 'required', 'string', 'max:100', function ($attribute, $value, $fail) {
                if (!CronExpression::isValidExpression($value)) {
                    $fail('检查计划格式不正确，例如 */5 * * * * 表示每 5 分钟。');
                }
            }],
            'base_url' => ['bail', 'nullable', 'string', 'url', 'max:500', function ($attribute, $value, $fail) {
                if (!NewApiChannelClient::isValidBaseUrl($value)) {
                    $fail('NewAPI 地址须为 HTTP(S) 站点地址，不能包含用户名、密码、查询参数或片段。');
                }
            }],
            'access_token' => ['nullable', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'clear_access_token' => 'sometimes|boolean',
            'user_id' => 'nullable|integer|min:1',
            'http_timeout' => 'required|integer|min:1|max:300',
        ]);

        $settings = ChannelRecoverySetting::current();
        $settings->fill([
            'enabled' => $request->boolean('enabled'),
            'schedule_cron' => $data['schedule_cron'],
            'base_url' => rtrim($data['base_url'] ?? '', '/'),
            'user_id' => $data['user_id'] ?? null,
            'http_timeout' => $data['http_timeout'],
        ]);

        if ($request->boolean('clear_access_token')) {
            $settings->access_token = null;
        } elseif (!empty($data['access_token'])) {
            $settings->access_token = $data['access_token'];
        }

        if ($settings->enabled && (!$settings->base_url || !$settings->user_id || !$settings->access_token)) {
            return back()->withErrors(['enabled' => '启用前请填写 NewAPI 地址、管理员用户 ID 和有效的访问令牌。'])
                ->withInput($request->except('access_token'));
        }

        $settings->save();

        return redirect()->route('admin.channel-recovery')
            ->with('status', '渠道恢复设置已保存，下次调度会使用新设置。');
    }
}
