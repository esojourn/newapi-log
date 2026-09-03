<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Log;
use App\Models\Token;

class StatsController extends Controller
{
    /**
     * CSV 导出日期范围上限（天），防止文件过大拖垮服务器
     */
    private const EXPORT_MAX_DAYS = 7;

    /**
     * 时间范围切换的可选天数；1 天走小时粒度，见 resolveRange()
     */
    private const RANGE_DAYS = [1, 3, 7, 30, 90];

    /**
     * 将 quota 转换为金额（quota / 500000）
     */
    private function quotaToAmount(int $quota): float
    {
        return round($quota / 500000, 4);
    }

    /**
     * other 列可安全交给 JSON 函数的守卫条件。
     *
     * other 可能是 NULL、空串或非法 JSON（老日志、充值等非消费类日志），
     * MySQL 8 对空串调用 JSON_EXTRACT 会直接抛 ERROR 3141。
     *
     * JSON_VALID 在 18 万行上约 1s，与一次 JSON_EXTRACT 同量级，所以组合表达式
     * （uncachedPromptExpr 等）里只在最外层套一次守卫，不要每个字段各套一遍。
     */
    private const OTHER_GUARD = "`other` IS NOT NULL AND `other` <> '' AND JSON_VALID(`other`)";

    /**
     * 从 logs.other（JSON 字符串）取整数字段，带守卫，缺失时返回 0。
     *
     * 单独使用时是安全的；若要和多个字段组合成一个大表达式，用 otherIntRaw()
     * 并在外层统一套一次 OTHER_GUARD，避免重复解析同一列。
     */
    private function otherInt(string $key): string
    {
        return "(CASE WHEN " . self::OTHER_GUARD
            . " THEN {$this->otherIntRaw($key)} ELSE 0 END)";
    }

    /**
     * 同 otherInt，但不带守卫——只能用在已被 OTHER_GUARD 包住的上下文里。
     */
    private function otherIntRaw(string $key): string
    {
        return "COALESCE(CAST(JSON_EXTRACT(`other`, '$.{$key}') AS SIGNED), 0)";
    }

    /**
     * 同 otherInt，但取小数（计费倍率），缺失时回落到 $default。
     */
    private function otherRatio(string $key, float $default): string
    {
        return "(CASE WHEN `other` IS NOT NULL AND `other` <> '' AND JSON_VALID(`other`)"
            . " THEN COALESCE(CAST(JSON_EXTRACT(`other`, '$.{$key}') AS DECIMAL(20,6)), {$default}) ELSE {$default} END)";
    }

    /**
     * prompt_tokens 是否「不含」缓存 token 的判定表达式。
     *
     * 上游 service/text_quota.go 只在非 Anthropic 语义时才从 baseTokens 里减掉
     * cache_tokens / cache_creation_tokens，即：
     *   - usage_semantic = 'anthropic'：prompt_tokens 不含缓存，直接作为新输入计费
     *   - 语义缺失但带 5m/1h 分档（isLegacyClaudeDerivedOpenAIUsage）：同上
     *   - 其余（OpenAI 语义）：prompt_tokens 含缓存，需相减
     *
     * 早期文档统一按「含缓存」处理，导致 Anthropic 日志的新输入被 GREATEST(0, ...)
     * 夹成 0、命中率虚高，故按语义分支。
     */
    private function promptExcludesCacheExpr(): string
    {
        // 语义只抽一次；legacy Claude 派生（语义缺失 + 有 5m/1h 分档）用
        // cache_write_tokens > cache_creation_tokens 近似判定，省掉两次
        // JSON_EXTRACT。实测本库该分支 0 行，留着是为了兼容旧上游写入。
        $semantic = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`other`, '$.usage_semantic')), '')";

        return "({$semantic} = 'anthropic' OR ({$semantic} = ''"
            . " AND {$this->cacheCreationTotalRaw()} > {$this->otherIntRaw('cache_creation_tokens')}))";
    }

    /**
     * 未命中缓存的新输入 token 表达式。
     *
     * prompt_tokens 是否含缓存由 promptExcludesCacheExpr() 决定；image_output
     * 在上游同样从 baseTokens 里扣除，单独按 image_ratio 计价，这里一并减掉。
     * other 非法时回落到 prompt_tokens 原值（无缓存可言）。
     */
    private function uncachedPromptExpr(): string
    {
        $inner = "GREATEST(0, prompt_tokens - {$this->otherIntRaw('image_output')}"
            . " - (CASE WHEN {$this->promptExcludesCacheExpr()} THEN 0"
            . " ELSE {$this->otherIntRaw('cache_tokens')} + {$this->otherIntRaw('cache_creation_tokens')} END))";

        return "(CASE WHEN " . self::OTHER_GUARD . " THEN {$inner} ELSE prompt_tokens END)";
    }

    /**
     * 缓存写入总量表达式。
     *
     * cache_creation_tokens 只记了 5 分钟那一档，1 小时缓存写在
     * cache_creation_tokens_1h。上游 cacheWriteTokensTotal() 的口径是：
     * 有分档时取分档之和（除非合并字段更大），否则取合并字段。
     */
    private function cacheCreationTotalExpr(): string
    {
        return "(CASE WHEN " . self::OTHER_GUARD
            . " THEN {$this->cacheCreationTotalRaw()} ELSE 0 END)";
    }

    /**
     * 同 cacheCreationTotalExpr，但不带守卫。
     *
     * 上游把 cacheWriteTokensTotal() 的结果直接写进 cache_write_tokens，
     * 实测全表 182797 行与 GREATEST(cache_creation_tokens, 5m + 1h) 完全一致，
     * 且 88105 个有缓存写入的行无一缺失该字段，所以优先取它——
     * 一次 JSON_EXTRACT 顶原来三次，18 万行上每次约 1s。
     * 老日志没有这个字段时回落到合并字段。
     */
    private function cacheCreationTotalRaw(): string
    {
        return "COALESCE(CAST(JSON_EXTRACT(`other`, '$.cache_write_tokens') AS SIGNED),"
            . " {$this->otherIntRaw('cache_creation_tokens')})";
    }

    /**
     * 缓存节省的 quota 表达式（预估）。
     *
     * 命中的 token 按 cache_ratio 打折计费，省下的即 (1 - cache_ratio) 那部分：
     *   cache_tokens × (1 - cache_ratio) × model_ratio × group_ratio
     * 按次计价（model_price > 0）时倍率不参与计费，公式不成立，记 0。
     */
    private function cacheSavedQuotaExpr(): string
    {
        return "(CASE WHEN {$this->otherRatio('model_price', 0)} > 0 THEN 0 ELSE"
            . " {$this->otherInt('cache_tokens')} * (1 - {$this->otherRatio('cache_ratio', 1)})"
            . " * {$this->otherRatio('model_ratio', 0)} * {$this->otherRatio('group_ratio', 1)} END)";
    }

    /**
     * 计费口径下的总输入 token 表达式（新输入 + 缓存读取 + 缓存写入）。
     *
     * prompt_tokens 在 Anthropic 语义下不含缓存，直接 SUM(prompt_tokens) 会漏掉
     * 全部缓存量（实测单用户低估约 785%），故统一用三段之和。
     */
    private function totalInputTokensExpr(): string
    {
        $ct = $this->otherIntRaw('cache_tokens');
        $cc = $this->otherIntRaw('cache_creation_tokens');

        // 总输入 = 新输入 + 缓存读取 + 缓存写入。
        // prompt_tokens 含缓存时要先减掉读取与写入（减的是合并字段，与上游
        // baseTokens.Sub(dCachedCreationTokens) 一致）。
        $uncached = "GREATEST(0, prompt_tokens - {$this->otherIntRaw('image_output')}"
            . " - (CASE WHEN {$this->promptExcludesCacheExpr()} THEN 0 ELSE {$ct} + {$cc} END))";

        // 守卫只套一层：JSON_VALID 与 JSON_EXTRACT 同量级（18 万行各约 1s），
        // 每个字段单独套守卫会让 90 天仪表盘慢好几倍
        return "(CASE WHEN " . self::OTHER_GUARD
            . " THEN {$uncached} + {$ct} + {$this->cacheCreationTotalRaw()}"
            . " ELSE prompt_tokens END)";
    }

    /**
     * 计费口径下的总 token 表达式（输入含缓存 + 输出）。
     */
    private function totalTokensExpr(): string
    {
        return "({$this->totalInputTokensExpr()} + completion_tokens)";
    }

    /**
     * 给总览对象挂上缓存派生值：命中率与预估节省金额。
     *
     * 命中率的分母取「缓存读取 + 缓存写入 + 未命中输入」而非 prompt_tokens，
     * 这样与堆叠柱图的三段口径完全一致；OpenRouter-Claude 特例下
     * uncached 被 clamp 到 0 会让两者产生分歧，用分段之和则天然不超过 100%。
     * 无输入时返回 null（视图显示 '-'）。
     */
    private function applyOverviewCache($overview): void
    {
        $totalInput = (int) $overview->total_cache_tokens
            + (int) $overview->total_cache_creation_tokens
            + (int) $overview->total_uncached_prompt_tokens;

        $overview->cache_hit_rate = $totalInput > 0
            ? round((int) $overview->total_cache_tokens / $totalInput * 100, 1)
            : null;
        $overview->cache_saved_amount = $this->quotaToAmount((int) round($overview->cache_saved_quota));

        // 总输入就是三段之和，直接复用，不必再让 SQL 跑一遍 totalInputTokensExpr
        $overview->total_input_tokens = $totalInput;
        if (isset($overview->total_completion_tokens)) {
            $overview->total_tokens = $totalInput + (int) $overview->total_completion_tokens;
        }
    }

    /**
     * 给用户维度的总览对象挂上缓存派生值，并把每日缓存趋势填进 $dailyData。
     *
     * userDetail / usage / publicUserDetail 三处共用，避免重复三份。
     */
    private function applyCacheStats($overview, array &$dailyData, $dailyTrend): void
    {
        $this->applyOverviewCache($overview);

        foreach ($dailyTrend as $row) {
            if (!isset($dailyData['cache_tokens'][$row->date])) {
                continue;
            }
            $dailyData['cache_tokens'][$row->date] = (int) $row->daily_cache_tokens;
            $dailyData['cache_creation_tokens'][$row->date] = (int) $row->daily_cache_creation_tokens;
            $dailyData['uncached_prompt_tokens'][$row->date] = (int) $row->daily_uncached_prompt_tokens;
        }
    }

    /**
     * 用户维度每日趋势查询上追加缓存字段（合并进现有查询，不额外扫表）。
     */
    private function selectDailyCache($query)
    {
        return $query
            ->selectRaw("COALESCE(SUM({$this->otherInt('cache_tokens')}), 0) as daily_cache_tokens")
            ->selectRaw("COALESCE(SUM({$this->cacheCreationTotalExpr()}), 0) as daily_cache_creation_tokens")
            ->selectRaw("COALESCE(SUM({$this->uncachedPromptExpr()}), 0) as daily_uncached_prompt_tokens");
    }

    /**
     * 总览查询上追加缓存字段（合并进现有查询，不额外扫表）。
     */
    private function selectOverviewCache($query)
    {
        return $query
            ->selectRaw("COALESCE(SUM({$this->otherInt('cache_tokens')}), 0) as total_cache_tokens")
            ->selectRaw("COALESCE(SUM({$this->cacheCreationTotalExpr()}), 0) as total_cache_creation_tokens")
            ->selectRaw("COALESCE(SUM({$this->uncachedPromptExpr()}), 0) as total_uncached_prompt_tokens")
            ->selectRaw("COALESCE(SUM({$this->cacheSavedQuotaExpr()}), 0) as cache_saved_quota");
    }

    /**
     * 每日缓存趋势的零填充骨架，保证图表在无数据的日期也有点位。
     */
    private function emptyCacheSeries(array $dates): array
    {
        return [
            'cache_tokens' => array_fill_keys($dates, 0),
            'cache_creation_tokens' => array_fill_keys($dates, 0),
            'uncached_prompt_tokens' => array_fill_keys($dates, 0),
        ];
    }

    /**
     * 解析 days 参数，返回 [天数, 是否小时粒度, 起始时间戳, 时间桶表达式, 桶键列表]。
     *
     * 缺省 days=1（最近 24 小时）—— 窗口最小、装载最快，页面再由用户手动切到更长范围。
     * days=1 走小时粒度：从当前整点往前推 23 小时，共 24 个整点桶（末桶是当前不完整的小时）；
     * 其余天数仍按自然日分桶。dashboard / userDetail / usage / publicUserDetail 四处共用。
     *
     * 桶键格式 'm-d H:00' 在 24 小时窗口内唯一，可直接当图表标签用，但它必须与 SQL 的
     * DATE_FORMAT 输出逐字一致 —— 否则 applyCacheStats() 等按键合并会静默落空、图表全 0。
     * 两侧时区都是 +08:00（config/app.php 与 database.connections.mysql.timezone）。
     */
    private function resolveRange(Request $request): array
    {
        $days = (int) $request->query('days', 1);
        if (!in_array($days, self::RANGE_DAYS, true)) {
            $days = 1;
        }

        $hourly = $days === 1;
        $now = Carbon::now();
        $since = $hourly
            ? $now->copy()->startOfHour()->subHours(23)
            : $now->copy()->subDays($days)->startOfDay();

        $bucketExpr = $hourly
            ? "DATE_FORMAT(FROM_UNIXTIME(created_at), '%m-%d %H:00')"
            : 'DATE(FROM_UNIXTIME(created_at))';

        $dates = [];
        for ($current = $since->copy(); $current->lte($now); $hourly ? $current->addHour() : $current->addDay()) {
            $dates[] = $hourly ? $current->format('m-d H') . ':00' : $current->format('Y-m-d');
        }

        return [$days, $hourly, $since->timestamp, $bucketExpr, $dates];
    }

    public function dashboard(Request $request)
    {
        [$days, $hourly, $sinceTimestamp, $bucketExpr, $dates] = $this->resolveRange($request);

        // 总览数据
        $overviewQuery = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->selectRaw('COUNT(*) as total_requests')
            // total_tokens 由 applyOverviewCache() 用三段缓存之和 + 输出算出，
            // 不在这里再跑一遍 totalTokensExpr（18 万行上能省数秒）
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) as total_completion_tokens')
            ->selectRaw('COALESCE(SUM(quota), 0) as total_quota')
            ->selectRaw('COUNT(DISTINCT token_name) as active_users');

        $overview = $this->selectOverviewCache($overviewQuery)->first();

        $overview->total_amount = $this->quotaToAmount($overview->total_quota);
        $this->applyOverviewCache($overview);

        // Top 10 用户用量（按金额排序）
        // 排除空 token_name：系统日志（type=3 渠道/设置变更、type=7）不带 token，
        // 混进排行会让视图的 route('admin.user.detail') 缺参数抛 500
        $topUsers = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->whereNotNull('token_name')
            ->where('token_name', '<>', '')
            ->groupBy('token_name')
            ->selectRaw('token_name')
            ->selectRaw('COUNT(*) as request_count')
            ->selectRaw("SUM({$this->totalInputTokensExpr()}) as prompt_tokens")
            ->selectRaw('SUM(completion_tokens) as completion_tokens')
            ->selectRaw("SUM({$this->totalTokensExpr()}) as total_tokens")
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
            ->selectRaw("token_name, model_name, SUM({$this->totalTokensExpr()}) as tokens")
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
            ->selectRaw("model_name, SUM({$this->totalTokensExpr()}) as total_tokens")
            ->orderByDesc('total_tokens')
            ->limit(10)
            ->get();

        // 缓存趋势的模型筛选：取用量前 5 的模型，逐模型的每日缓存分段与预估节省
        $cacheModelNames = $modelDistribution->take(5)->pluck('model_name')->toArray();

        $modelCacheTrendQuery = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->whereIn('model_name', $cacheModelNames)
            ->groupBy('date', 'model_name')
            ->selectRaw("{$bucketExpr} as date, model_name")
            ->selectRaw("COALESCE(SUM({$this->cacheSavedQuotaExpr()}), 0) as daily_cache_saved_quota")
            ->orderBy('date');

        $modelCacheTrend = $this->selectDailyCache($modelCacheTrendQuery)->get();

        // 每日用量趋势（Top 10 用户）
        $dailyTrend = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->whereIn('token_name', $topUserNames)
            ->groupBy('date', 'token_name')
            ->selectRaw("{$bucketExpr} as date, token_name, SUM({$this->totalTokensExpr()}) as daily_tokens, SUM(quota) as daily_quota")
            ->orderBy('date')
            ->get();

        // 每日总金额（缓存字段合并进同一次扫描）
        $dailyAmountsQuery = DB::table('logs')
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date')
            ->selectRaw("{$bucketExpr} as date, SUM(quota) as daily_quota")
            ->orderBy('date');

        $dailyAmounts = $this->selectDailyCache($dailyAmountsQuery)
            ->get()
            ->keyBy('date');

        // 整理每日趋势数据为图表格式
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

        // 全站每日缓存趋势
        $dailyCacheData = $this->emptyCacheSeries($dates);
        foreach ($dates as $date) {
            $row = $dailyAmounts->get($date);
            if (!$row) {
                continue;
            }
            $dailyCacheData['cache_tokens'][$date] = (int) $row->daily_cache_tokens;
            $dailyCacheData['cache_creation_tokens'][$date] = (int) $row->daily_cache_creation_tokens;
            $dailyCacheData['uncached_prompt_tokens'][$date] = (int) $row->daily_uncached_prompt_tokens;
        }

        // 逐模型的每日缓存趋势（供缓存图表的模型筛选按钮切换）
        $modelCacheData = [];
        $modelCacheSavedQuota = [];
        foreach ($cacheModelNames as $model) {
            $modelCacheData[$model] = $this->emptyCacheSeries($dates);
            $modelCacheSavedQuota[$model] = 0.0;
        }
        foreach ($modelCacheTrend as $row) {
            if (!isset($modelCacheData[$row->model_name]['cache_tokens'][$row->date])) {
                continue;
            }
            $modelCacheData[$row->model_name]['cache_tokens'][$row->date] = (int) $row->daily_cache_tokens;
            $modelCacheData[$row->model_name]['cache_creation_tokens'][$row->date] = (int) $row->daily_cache_creation_tokens;
            $modelCacheData[$row->model_name]['uncached_prompt_tokens'][$row->date] = (int) $row->daily_uncached_prompt_tokens;
            $modelCacheSavedQuota[$row->model_name] += (float) $row->daily_cache_saved_quota;
        }

        $modelCacheSaved = [];
        foreach ($modelCacheSavedQuota as $model => $quota) {
            $modelCacheSaved[$model] = $this->quotaToAmount((int) round($quota));
        }

        return view('admin.dashboard', compact(
            'days',
            'hourly',
            'overview',
            'topUsers',
            'primaryModels',
            'modelDistribution',
            'dates',
            'dailyData',
            'dailyAmountData',
            'topUserNames',
            'dailyAmounts',
            'dailyCacheData',
            'cacheModelNames',
            'modelCacheData',
            'modelCacheSaved'
        ));
    }

    /**
     * 用户详情页面 - 统计 Tab
     */
    public function userDetail(Request $request, string $tokenName)
    {
        [$days, $hourly, $sinceTimestamp, $bucketExpr, $dates] = $this->resolveRange($request);

        $token = Token::where('name', $tokenName)->first();
        $balance = $token
            ? ($token->unlimited_quota ? '无限' : '$' . number_format($token->remain_quota / 500000, 4))
            : '-';

        // 用户总览统计
        $overviewQuery = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(quota), 0) as total_quota')
            ->selectRaw("COALESCE(SUM({$this->totalInputTokensExpr()}), 0) as total_prompt_tokens")
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) as total_completion_tokens');

        $overview = $this->selectOverviewCache($overviewQuery)->first();

        $overview->total_amount = $this->quotaToAmount($overview->total_quota);

        // 消费趋势（按 resolveRange() 给出的时间桶分组：自然日或整点小时）
        $dailyTrendQuery = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date')
            ->selectRaw("{$bucketExpr} as date")
            ->selectRaw('SUM(quota) as daily_quota')
            ->selectRaw("SUM({$this->totalInputTokensExpr()}) as daily_prompt_tokens")
            ->selectRaw('SUM(completion_tokens) as daily_completion_tokens')
            ->selectRaw('COUNT(*) as daily_requests')
            ->orderBy('date');

        $dailyTrend = $this->selectDailyCache($dailyTrendQuery)->get();

        $dailyData = array_merge([
            'amounts' => array_fill_keys($dates, 0),
            'requests' => array_fill_keys($dates, 0),
            'prompt_tokens' => array_fill_keys($dates, 0),
            'completion_tokens' => array_fill_keys($dates, 0),
        ], $this->emptyCacheSeries($dates));

        foreach ($dailyTrend as $row) {
            if (isset($dailyData['amounts'][$row->date])) {
                $dailyData['amounts'][$row->date] = $this->quotaToAmount($row->daily_quota);
                $dailyData['requests'][$row->date] = (int) $row->daily_requests;
                $dailyData['prompt_tokens'][$row->date] = (int) $row->daily_prompt_tokens;
                $dailyData['completion_tokens'][$row->date] = (int) $row->daily_completion_tokens;
            }
        }

        $this->applyCacheStats($overview, $dailyData, $dailyTrend);

        // 每日模型金额分布（用于堆叠柱图）
        $dailyModelTrend = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date', 'model_name')
            ->selectRaw("{$bucketExpr} as date, model_name, SUM(quota) as daily_quota")
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
            ->selectRaw("SUM({$this->totalTokensExpr()}) as total_tokens")
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

        $logsUrl = "/admin/user/{$tokenName}/logs";
        $hourlyUrl = "/admin/user/{$tokenName}/hourly";
        $exportUrl = "/admin/user/{$tokenName}/logs/export";

        return view('admin.user-detail', compact(
            'tokenName',
            'days',
            'hourly',
            'overview',
            'dates',
            'dailyData',
            'dailyModelData',
            'dailyModelNames',
            'modelDistribution',
            'groupDistribution',
            'balance',
            'logsUrl',
            'hourlyUrl',
            'exportUrl'
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
                $cache = Log::cacheTokens($log);

                return [
                    'id' => $log->id,
                    'created_at' => date('Y-m-d H:i:s', $log->created_at),
                    'model_name' => $log->model_name,
                    'group' => $log->group,
                    // prompt_tokens 在 Anthropic 语义下不含缓存，直接展示会误导，
                    // 统一改成「未命中的新输入」，与缓存两列同口径
                    'prompt_tokens' => $cache['uncached_prompt_tokens'],
                    'cache_tokens' => $cache['cache_tokens'],
                    'cache_creation_tokens' => $cache['cache_creation_tokens'],
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
     * 用户日志导出 CSV（按日期范围导出，范围上限 7 天，避免文件过大）
     */
    public function userLogsExport(Request $request, string $tokenName)
    {
        try {
            $start = Carbon::createFromFormat('Y-m-d', (string) $request->query('start'))->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', (string) $request->query('end'))->endOfDay();
        } catch (\Exception $e) {
            abort(422, '日期格式错误');
        }

        if ($start->gt($end)) {
            abort(422, '开始日期不能晚于结束日期');
        }
        if ($start->diffInDays($end) >= self::EXPORT_MAX_DAYS) {
            abort(422, '导出日期范围最多 ' . self::EXPORT_MAX_DAYS . ' 天');
        }

        $startTimestamp = $start->timestamp;
        $endTimestamp = $end->timestamp;

        $filename = sprintf(
            'logs_%s_%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_\-]+/', '_', $tokenName),
            $start->format('Ymd'),
            $end->format('Ymd')
        );

        return response()->streamDownload(function () use ($tokenName, $startTimestamp, $endTimestamp) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM，避免 Excel 打开中文乱码
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['时间', '模型', '分组', '新输入 Tokens', '缓存读取 Tokens', '缓存写入 Tokens', '输出 Tokens', '金额($)', '耗时(秒)', '流式']);

            // 防止以 = + - @ 开头的文本在 Excel 中被当作公式执行
            $sanitize = function ($value) {
                $value = (string) $value;
                if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
                    return "'" . $value;
                }
                return $value;
            };

            $rows = DB::table('logs')
                ->where('token_name', $tokenName)
                ->where('created_at', '>=', $startTimestamp)
                ->where('created_at', '<=', $endTimestamp)
                ->orderByDesc('created_at')
                ->cursor();

            $count = 0;
            foreach ($rows as $log) {
                $cache = Log::cacheTokens($log);
                fputcsv($out, [
                    date('Y-m-d H:i:s', $log->created_at),
                    $sanitize($log->model_name),
                    $sanitize($log->group),
                    $cache['uncached_prompt_tokens'],
                    $cache['cache_tokens'],
                    $cache['cache_creation_tokens'],
                    $log->completion_tokens,
                    $this->quotaToAmount($log->quota),
                    $log->use_time,
                    $log->is_stream ? '是' : '否',
                ]);
                if (++$count % 1000 === 0) {
                    flush();
                }
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * 某天每小时消费明细 API
     */
    public function userHourly(Request $request, string $tokenName)
    {
        try {
            $day = Carbon::createFromFormat('Y-m-d', (string) $request->query('date'))->startOfDay();
        } catch (\Exception $e) {
            return response()->json(['error' => '日期格式错误'], 422);
        }

        $start = $day->copy()->startOfDay()->timestamp;
        $end = $day->copy()->endOfDay()->timestamp;

        $rows = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->groupBy('hour')
            ->selectRaw('HOUR(FROM_UNIXTIME(created_at)) as hour')
            ->selectRaw('SUM(quota) as quota')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM({$this->uncachedPromptExpr()}) as prompt_tokens")
            ->selectRaw('SUM(completion_tokens) as completion_tokens')
            ->selectRaw("COALESCE(SUM({$this->otherInt('cache_tokens')}), 0) as cache_tokens")
            ->selectRaw("COALESCE(SUM({$this->cacheCreationTotalExpr()}), 0) as cache_creation_tokens")
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        $hours = [];
        $totalAmount = 0;
        $totalRequests = 0;
        for ($h = 0; $h < 24; $h++) {
            $row = $rows->get($h);
            $amount = $row ? $this->quotaToAmount($row->quota) : 0;
            $requests = $row ? (int) $row->requests : 0;
            $totalAmount += $amount;
            $totalRequests += $requests;
            $hours[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'amount' => $amount,
                'requests' => $requests,
                'prompt_tokens' => $row ? (int) $row->prompt_tokens : 0,
                'cache_tokens' => $row ? (int) $row->cache_tokens : 0,
                'cache_creation_tokens' => $row ? (int) $row->cache_creation_tokens : 0,
                'completion_tokens' => $row ? (int) $row->completion_tokens : 0,
            ];
        }

        return response()->json([
            'date' => $day->format('Y-m-d'),
            'hours' => $hours,
            'total_amount' => round($totalAmount, 4),
            'total_requests' => $totalRequests,
        ]);
    }

    /**
     * 首页 API Key 认证（POST /）
     */
    public function authenticate(Request $request)
    {
        $request->validate([
            'api_key' => 'required|string',
        ]);

        $apikey = trim($request->input('api_key'));
        $processedKey = substr($apikey, 3);
        $token = Token::where('key', $processedKey)->first();

        if (!$token) {
            return back()->withErrors(['api_key' => 'API Key 无效'])->withInput();
        }

        $request->session()->put('user_api_key', $apikey);
        $request->session()->put('user_token_name', $token->name);

        return redirect('/usage');
    }

    /**
     * 用户使用详情页面（Session 认证，GET /usage）
     */
    public function usage(Request $request)
    {
        $tokenName = session('user_token_name');
        if (!$tokenName) {
            return redirect('/');
        }

        $apikey = session('user_api_key');
        $processedKey = substr($apikey, 3);
        $token = Token::where('key', $processedKey)->first();

        if (!$token) {
            $request->session()->forget(['user_api_key', 'user_token_name']);
            return redirect('/');
        }

        $balance = $token->unlimited_quota
            ? '无限'
            : '$' . number_format($token->remain_quota / 500000, 4);

        [$days, $hourly, $sinceTimestamp, $bucketExpr, $dates] = $this->resolveRange($request);

        $overviewQuery = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(quota), 0) as total_quota')
            ->selectRaw("COALESCE(SUM({$this->totalInputTokensExpr()}), 0) as total_prompt_tokens")
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) as total_completion_tokens');

        $overview = $this->selectOverviewCache($overviewQuery)->first();

        $overview->total_amount = $this->quotaToAmount($overview->total_quota);

        $dailyTrendQuery = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date')
            ->selectRaw("{$bucketExpr} as date")
            ->selectRaw('SUM(quota) as daily_quota')
            ->selectRaw("SUM({$this->totalInputTokensExpr()}) as daily_prompt_tokens")
            ->selectRaw('SUM(completion_tokens) as daily_completion_tokens')
            ->selectRaw('COUNT(*) as daily_requests')
            ->orderBy('date');

        $dailyTrend = $this->selectDailyCache($dailyTrendQuery)->get();

        $dailyData = array_merge([
            'amounts' => array_fill_keys($dates, 0),
            'requests' => array_fill_keys($dates, 0),
            'prompt_tokens' => array_fill_keys($dates, 0),
            'completion_tokens' => array_fill_keys($dates, 0),
        ], $this->emptyCacheSeries($dates));

        foreach ($dailyTrend as $row) {
            if (isset($dailyData['amounts'][$row->date])) {
                $dailyData['amounts'][$row->date] = $this->quotaToAmount($row->daily_quota);
                $dailyData['requests'][$row->date] = (int) $row->daily_requests;
                $dailyData['prompt_tokens'][$row->date] = (int) $row->daily_prompt_tokens;
                $dailyData['completion_tokens'][$row->date] = (int) $row->daily_completion_tokens;
            }
        }

        $this->applyCacheStats($overview, $dailyData, $dailyTrend);

        $dailyModelTrend = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date', 'model_name')
            ->selectRaw("{$bucketExpr} as date, model_name, SUM(quota) as daily_quota")
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
            ->selectRaw("SUM({$this->totalTokensExpr()}) as total_tokens")
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
        $isSession = true;
        $logsUrl = '/usage/logs';
        $hourlyUrl = '/usage/hourly';

        return view('admin.user-detail', compact(
            'tokenName',
            'days',
            'hourly',
            'overview',
            'dates',
            'dailyData',
            'dailyModelData',
            'dailyModelNames',
            'modelDistribution',
            'groupDistribution',
            'balance',
            'isPublic',
            'isSession',
            'logsUrl',
            'hourlyUrl'
        ));
    }

    /**
     * 用户日志 API（Session 认证，GET /usage/logs）
     */
    public function usageLogs(Request $request)
    {
        $tokenName = session('user_token_name');
        if (!$tokenName) {
            return response()->json(['error' => '未认证'], 401);
        }

        return $this->userLogs($request, $tokenName);
    }

    /**
     * 某天每小时消费明细 API（Session 认证，GET /usage/hourly）
     */
    public function usageHourly(Request $request)
    {
        $tokenName = session('user_token_name');
        if (!$tokenName) {
            return response()->json(['error' => '未认证'], 401);
        }

        return $this->userHourly($request, $tokenName);
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

        [$days, $hourly, $sinceTimestamp, $bucketExpr, $dates] = $this->resolveRange($request);

        $overviewQuery = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw('COALESCE(SUM(quota), 0) as total_quota')
            ->selectRaw("COALESCE(SUM({$this->totalInputTokensExpr()}), 0) as total_prompt_tokens")
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) as total_completion_tokens');

        $overview = $this->selectOverviewCache($overviewQuery)->first();

        $overview->total_amount = $this->quotaToAmount($overview->total_quota);

        $dailyTrendQuery = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date')
            ->selectRaw("{$bucketExpr} as date")
            ->selectRaw('SUM(quota) as daily_quota')
            ->selectRaw("SUM({$this->totalInputTokensExpr()}) as daily_prompt_tokens")
            ->selectRaw('SUM(completion_tokens) as daily_completion_tokens')
            ->selectRaw('COUNT(*) as daily_requests')
            ->orderBy('date');

        $dailyTrend = $this->selectDailyCache($dailyTrendQuery)->get();

        $dailyData = array_merge([
            'amounts' => array_fill_keys($dates, 0),
            'requests' => array_fill_keys($dates, 0),
            'prompt_tokens' => array_fill_keys($dates, 0),
            'completion_tokens' => array_fill_keys($dates, 0),
        ], $this->emptyCacheSeries($dates));

        foreach ($dailyTrend as $row) {
            if (isset($dailyData['amounts'][$row->date])) {
                $dailyData['amounts'][$row->date] = $this->quotaToAmount($row->daily_quota);
                $dailyData['requests'][$row->date] = (int) $row->daily_requests;
                $dailyData['prompt_tokens'][$row->date] = (int) $row->daily_prompt_tokens;
                $dailyData['completion_tokens'][$row->date] = (int) $row->daily_completion_tokens;
            }
        }

        $this->applyCacheStats($overview, $dailyData, $dailyTrend);

        $dailyModelTrend = DB::table('logs')
            ->where('token_name', $tokenName)
            ->where('created_at', '>=', $sinceTimestamp)
            ->groupBy('date', 'model_name')
            ->selectRaw("{$bucketExpr} as date, model_name, SUM(quota) as daily_quota")
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
            ->selectRaw("SUM({$this->totalTokensExpr()}) as total_tokens")
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
        $logsUrl = "/user/{$apikey}/logs";
        $hourlyUrl = "/user/{$apikey}/hourly";

        return view('admin.user-detail', compact(
            'tokenName',
            'days',
            'hourly',
            'overview',
            'dates',
            'dailyData',
            'dailyModelData',
            'dailyModelNames',
            'modelDistribution',
            'groupDistribution',
            'balance',
            'isPublic',
            'apikey',
            'logsUrl',
            'hourlyUrl'
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

    /**
     * 公开访问某天每小时消费明细 API（通过 API Key）
     */
    public function publicUserHourly(Request $request, string $apikey)
    {
        $processedKey = substr($apikey, 3);
        $token = Token::where('key', $processedKey)->first();

        if (!$token) {
            abort(404);
        }

        return $this->userHourly($request, $token->name);
    }
}
