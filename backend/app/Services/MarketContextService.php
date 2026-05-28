<?php

namespace App\Services;

use App\Models\DailyQuote;
use App\Models\UsMarketIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 市場情境判斷服務
 *
 * 根據美股指數、台指期等隔夜資訊，判斷今日市場情境，
 * 供選股（Haiku/Opus）和盤中監控動態調整行為。
 *
 * 盤後執行（18:50 持倉檢討）時，會額外讀取當日台股大盤廣度（daily_quotes 聚合）
 * 並在當日台股與海外訊號衝突時以當日台股為準——避免昨晚利多但今日台股恐慌時，
 * AI 仍帶著錯誤的 bullish 標籤推理。
 */
class MarketContextService
{
    // 情境標籤
    public const CONTEXT_NORMAL = 'normal';
    public const CONTEXT_BULLISH_CATALYST = 'bullish_catalyst';
    public const CONTEXT_BEARISH_PANIC = 'bearish_panic';
    public const CONTEXT_SECTOR_ROTATION = 'sector_rotation';

    // 隔夜訊號閾值
    private const SOX_STRONG_THRESHOLD = 3.0;    // 費半 >+3% 視為強催化
    private const TX_STRONG_THRESHOLD = 1.5;      // 台指期 >+1.5% 視為強催化
    private const SOX_WEAK_THRESHOLD = -3.0;      // 費半 <-3% 視為恐慌
    private const TX_WEAK_THRESHOLD = -1.5;       // 台指期 <-1.5% 視為恐慌

    // 當日台股大盤廣度閾值（盤後 daily_quotes 已寫入時才使用）
    //
    // 設計原則：避免分歧日（dn5/up5 都過 50）兩邊同時 fire 互相抵銷。
    // 三條訊號都要求「方向明確」，避免單一絕對數據觸發。
    private const INTRADAY_MIN_QUOTES = 500;          // 少於 500 檔視為尚未寫入，跳過
    // 恐慌條件（任一成立）
    private const INTRADAY_PANIC_DOWN5_RATIO = 2.0;   // 跌幅>5% 檔數 ≥ 漲幅>5% 檔數 × 2 且 dn5 ≥ 50
    private const INTRADAY_PANIC_DOWN5_MIN = 50;
    private const INTRADAY_PANIC_AVG_CHANGE = -1.0;   // 全體平均 ≤-1%
    private const INTRADAY_PANIC_AD_RATIO = 1.8;      // 跌:漲 ≥1.8 且平均 ≤-0.3
    private const INTRADAY_PANIC_AD_AVG = -0.3;
    // 催化條件（任一成立，對稱）
    private const INTRADAY_BULL_UP5_RATIO = 2.0;
    private const INTRADAY_BULL_UP5_MIN = 50;
    private const INTRADAY_BULL_AVG_CHANGE = 1.0;
    private const INTRADAY_BULL_AD_RATIO = 1.8;
    private const INTRADAY_BULL_AD_AVG = 0.3;

    // 產業催化映射：費半大漲時，哪些產業可能受益
    private const SOX_BENEFICIARY_INDUSTRIES = [
        '半導體業', '電子零組件業', '光電業', '通信網路業',
        '電腦及週邊設備業', '電子通路業', '資訊服務業',
    ];

    /**
     * 偵測今日市場情境
     *
     * @return array{
     *   label: string,
     *   triggers: string[],
     *   hint: string,
     *   beneficiary_industries: string[],
     *   sox_change: float|null,
     *   tx_change: float|null,
     *   intraday: array|null,
     * }
     */
    public static function detect(string $tradeDate): array
    {
        $overnight = self::detectOvernight($tradeDate);
        $intraday = self::detectTaiwanIntraday($tradeDate);

        if ($intraday === null) {
            // 盤前執行（daily_quotes 未寫入）— 維持原行為
            return array_merge($overnight, ['intraday' => null]);
        }

        return self::mergeOvernightAndIntraday($overnight, $intraday);
    }

    /**
     * 隔夜訊號判定（原 detect 邏輯）
     */
    private static function detectOvernight(string $tradeDate): array
    {
        $indices = UsMarketIndex::where('date', $tradeDate)->get();

        $sox = $indices->firstWhere('symbol', '^SOX');
        $tx = $indices->firstWhere('symbol', 'TX');
        $nasdaq = $indices->firstWhere('symbol', '^IXIC');

        $soxChange = $sox ? (float) $sox->change_percent : null;
        $txChange = $tx ? (float) $tx->change_percent : null;

        $base = [
            'sox_change' => $soxChange,
            'tx_change' => $txChange,
        ];

        // 利多催化：費半或台指期大漲
        if (($soxChange !== null && $soxChange >= self::SOX_STRONG_THRESHOLD)
            || ($txChange !== null && $txChange >= self::TX_STRONG_THRESHOLD)) {

            $triggers = [];
            $beneficiaries = [];

            if ($soxChange !== null && $soxChange >= self::SOX_STRONG_THRESHOLD) {
                $triggers[] = "費半+{$soxChange}%";
                $beneficiaries = self::SOX_BENEFICIARY_INDUSTRIES;
            }
            if ($txChange !== null && $txChange >= self::TX_STRONG_THRESHOLD) {
                $triggers[] = "台指期+{$txChange}%";
            }

            $nasdaqChange = $nasdaq ? (float) $nasdaq->change_percent : null;
            if ($nasdaqChange !== null && $nasdaqChange >= 2.0) {
                $triggers[] = "那斯達克+{$nasdaqChange}%";
            }

            return array_merge($base, [
                'label' => self::CONTEXT_BULLISH_CATALYST,
                'triggers' => $triggers,
                'hint' => self::buildBullishHint($triggers, $beneficiaries),
                'beneficiary_industries' => $beneficiaries,
            ]);
        }

        // 利空恐慌：費半或台指期大跌
        if (($soxChange !== null && $soxChange <= self::SOX_WEAK_THRESHOLD)
            || ($txChange !== null && $txChange <= self::TX_WEAK_THRESHOLD)) {

            $triggers = [];
            if ($soxChange !== null && $soxChange <= self::SOX_WEAK_THRESHOLD) {
                $triggers[] = "費半{$soxChange}%";
            }
            if ($txChange !== null && $txChange <= self::TX_WEAK_THRESHOLD) {
                $triggers[] = "台指期{$txChange}%";
            }

            return array_merge($base, [
                'label' => self::CONTEXT_BEARISH_PANIC,
                'triggers' => $triggers,
                'hint' => '國際利空衝擊，開盤可能跳空下殺。收緊選股標準、降低倉位、提高進場門檻。'
                    . '避免逆勢做多弱勢股，僅考慮超強勢個股的抗跌反彈。',
                'beneficiary_industries' => [],
            ]);
        }

        return array_merge($base, [
            'label' => self::CONTEXT_NORMAL,
            'triggers' => [],
            'hint' => '',
            'beneficiary_industries' => [],
        ]);
    }

    /**
     * 當日台股大盤廣度訊號（盤後 daily_quotes 已寫入時）
     *
     * @return array{label:string, avg_change:float, down5:int, up5:int,
     *               advances:int, declines:int, limit_down:int, limit_up:int,
     *               total:int, summary:string}|null  null = 該日 quotes 未寫入或樣本不足
     */
    private static function detectTaiwanIntraday(string $tradeDate): ?array
    {
        $row = DB::table('daily_quotes')
            ->selectRaw("
                count(*) as total,
                avg(change_percent) as avg_chg,
                sum(case when change_percent >= 5  then 1 else 0 end) as up5,
                sum(case when change_percent <= -5 then 1 else 0 end) as dn5,
                sum(case when change_percent >= 9.5 then 1 else 0 end) as lim_up,
                sum(case when change_percent <= -9.5 then 1 else 0 end) as lim_dn,
                sum(case when change_percent > 0  then 1 else 0 end) as advances,
                sum(case when change_percent < 0  then 1 else 0 end) as declines
            ")
            ->where('date', $tradeDate)
            ->where('volume', '>', 1000)
            ->first();

        if ($row === null || (int) $row->total < self::INTRADAY_MIN_QUOTES) {
            return null;
        }

        $total = (int) $row->total;
        $avgChg = (float) $row->avg_chg;
        $up5 = (int) $row->up5;
        $dn5 = (int) $row->dn5;
        $advances = (int) $row->advances;
        $declines = (int) $row->declines;
        $limUp = (int) $row->lim_up;
        $limDn = (int) $row->lim_dn;

        // 恐慌判定（三條任一成立、且要求方向明確）
        $isPanic = (
            // (a) 跌幅>5% 檔數 ≥ 漲幅>5% 檔數 × 2 且 dn5 ≥ 50
            $dn5 >= self::INTRADAY_PANIC_DOWN5_MIN
            && $up5 > 0
            && $dn5 >= $up5 * self::INTRADAY_PANIC_DOWN5_RATIO
        ) || (
            // (b) 全體平均 ≤-1%
            $avgChg <= self::INTRADAY_PANIC_AVG_CHANGE
        ) || (
            // (c) 跌:漲 ≥1.8 且平均 ≤-0.3
            $advances > 0
            && $declines >= $advances * self::INTRADAY_PANIC_AD_RATIO
            && $avgChg <= self::INTRADAY_PANIC_AD_AVG
        );

        // 催化判定（對稱）
        $isBull = (
            $up5 >= self::INTRADAY_BULL_UP5_MIN
            && $dn5 > 0
            && $up5 >= $dn5 * self::INTRADAY_BULL_UP5_RATIO
        ) || (
            $avgChg >= self::INTRADAY_BULL_AVG_CHANGE
        ) || (
            $declines > 0
            && $advances >= $declines * self::INTRADAY_BULL_AD_RATIO
            && $avgChg >= self::INTRADAY_BULL_AD_AVG
        );

        $label = self::CONTEXT_NORMAL;
        if ($isPanic && !$isBull) {
            $label = self::CONTEXT_BEARISH_PANIC;
        } elseif ($isBull && !$isPanic) {
            $label = self::CONTEXT_BULLISH_CATALYST;
        }

        $summary = sprintf(
            '%d 檔有量；漲:跌=%d:%d；平均%+.2f%%；跌幅>5%%共%d檔、漲幅>5%%共%d檔；跌停%d、漲停%d',
            $total, $advances, $declines, $avgChg, $dn5, $up5, $limDn, $limUp
        );

        return [
            'label' => $label,
            'avg_change' => round($avgChg, 2),
            'up5' => $up5,
            'down5' => $dn5,
            'advances' => $advances,
            'declines' => $declines,
            'limit_up' => $limUp,
            'limit_down' => $limDn,
            'total' => $total,
            'summary' => $summary,
        ];
    }

    /**
     * 衝突解決：當日台股訊號優先於隔夜（更接近實際）
     */
    private static function mergeOvernightAndIntraday(array $overnight, array $intraday): array
    {
        $overnightLabel = $overnight['label'];
        $intradayLabel = $intraday['label'];
        $intradayBag = ['intraday' => $intraday];

        // 1. 當日台股 normal → 維持隔夜判定（隔夜訊號為主）
        if ($intradayLabel === self::CONTEXT_NORMAL) {
            return array_merge($overnight, $intradayBag);
        }

        // 2. 當日台股與隔夜同向 → 維持隔夜，但 trigger 補充
        if ($overnightLabel === $intradayLabel) {
            $extra = $intradayLabel === self::CONTEXT_BEARISH_PANIC
                ? "台股當日恐慌（{$intraday['summary']}）"
                : "台股當日同步走強（{$intraday['summary']}）";
            return array_merge($overnight, $intradayBag, [
                'triggers' => array_merge($overnight['triggers'], [$extra]),
            ]);
        }

        // 3. 衝突 → 以當日台股為準，覆蓋隔夜
        if ($intradayLabel === self::CONTEXT_BEARISH_PANIC) {
            $override = sprintf(
                '台股當日恐慌覆蓋海外訊號（%s）',
                $intraday['summary']
            );
            $triggers = [$override];
            if (!empty($overnight['triggers'])) {
                $triggers[] = '海外隔夜：' . implode('、', $overnight['triggers'])
                    . '（不採信，當日台股已反向）';
            }
            Log::info("MarketContext {$intraday['summary']}: 當日台股覆蓋隔夜為利空恐慌");
            return array_merge($overnight, $intradayBag, [
                'label' => self::CONTEXT_BEARISH_PANIC,
                'triggers' => $triggers,
                'hint' => '當日台股大盤已明顯恐慌（多檔重挫、平均收黑）。即使昨晚海外利多，台股已自主反向。'
                    . '個股若同步暴跌，先以「市場拖累」為基底解釋，避免把市場性恐慌歸因為個股 thesis 失效；'
                    . '已破停損的持倉重審時，「修復條件」應以大盤先止穩為前提，而非個股自行跳空高開。',
                'beneficiary_industries' => [],
            ]);
        }

        // intraday=bull_catalyst, overnight=panic
        $override = sprintf(
            '台股當日強漲覆蓋海外訊號（%s）',
            $intraday['summary']
        );
        $triggers = [$override];
        if (!empty($overnight['triggers'])) {
            $triggers[] = '海外隔夜：' . implode('、', $overnight['triggers'])
                . '（不採信，當日台股已反向）';
        }
        Log::info("MarketContext {$intraday['summary']}: 當日台股覆蓋隔夜為利多催化");
        return array_merge($overnight, $intradayBag, [
            'label' => self::CONTEXT_BULLISH_CATALYST,
            'triggers' => $triggers,
            'hint' => self::buildBullishHint($triggers, self::SOX_BENEFICIARY_INDUSTRIES),
            'beneficiary_industries' => self::SOX_BENEFICIARY_INDUSTRIES,
        ]);
    }

    /**
     * 判斷某產業是否為今日催化受益產業
     * 注意：若 stock.industry 為空，回傳 true（交由 AI 判斷）
     */
    public static function isBeneficiaryIndustry(?string $industry, array $context): bool
    {
        // 沒有 industry 資料時，不做產業篩選（讓 AI 判斷）
        if (empty($industry)) {
            return true;
        }
        return in_array($industry, $context['beneficiary_industries'] ?? [], true);
    }

    /**
     * 判斷是否為催化日（利多）
     */
    public static function isBullishCatalyst(array $context): bool
    {
        return ($context['label'] ?? '') === self::CONTEXT_BULLISH_CATALYST;
    }

    /**
     * 判斷是否為恐慌日（利空）
     */
    public static function isBearishPanic(array $context): bool
    {
        return ($context['label'] ?? '') === self::CONTEXT_BEARISH_PANIC;
    }

    /**
     * 生成 AI prompt 用的情境段落
     */
    public static function toPromptSection(array $context): string
    {
        if ($context['label'] === self::CONTEXT_NORMAL) {
            return '';
        }

        $labelMap = [
            self::CONTEXT_BULLISH_CATALYST => '🔥 利多催化日',
            self::CONTEXT_BEARISH_PANIC => '⚠️ 利空恐慌日',
            self::CONTEXT_SECTOR_ROTATION => '🔄 產業輪動日',
        ];

        $label = $labelMap[$context['label']] ?? $context['label'];
        $triggers = implode('、', $context['triggers'] ?? []);

        $section = "## 今日市場情境：{$label}\n";
        $section .= "觸發條件：{$triggers}\n";
        $section .= $context['hint'];

        if (!empty($context['beneficiary_industries'])) {
            $section .= "\n受益產業：" . implode('、', $context['beneficiary_industries']);
        }

        return $section;
    }

    private static function buildBullishHint(array $triggers, array $beneficiaries): string
    {
        $hint = '國際利多催化，開盤可能大幅跳空。';
        $hint .= '近期超跌（5日跌幅>8%）且屬受益產業的標的有強力反彈機會。';
        $hint .= "\n**選股調整**：";
        $hint .= "\n- 放寬空頭排列限制：超跌+強催化=反彈空間大，不應因均線排列排除";
        $hint .= "\n- 加入 gap_reversal（跳空反轉）策略：適用於超跌股跳空開高後直攻的情境";
        $hint .= "\n- 跳空格局下買入價應參考開盤價，而非昨收附近的支撐位";
        $hint .= "\n**風險提醒**：催化反彈可能只是一日行情，停損要嚴格";

        return $hint;
    }
}
