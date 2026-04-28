<?php

namespace App\Services;

use App\Models\UsMarketIndex;
use Illuminate\Support\Facades\Log;

/**
 * 市場情境判斷服務
 *
 * 根據美股指數、台指期等隔夜資訊，判斷今日市場情境，
 * 供選股（Haiku/Opus）和盤中監控動態調整行為。
 */
class MarketContextService
{
    // 情境標籤
    public const CONTEXT_NORMAL = 'normal';
    public const CONTEXT_BULLISH_CATALYST = 'bullish_catalyst';
    public const CONTEXT_BEARISH_PANIC = 'bearish_panic';
    public const CONTEXT_SECTOR_ROTATION = 'sector_rotation';

    // 閾值
    private const SOX_STRONG_THRESHOLD = 3.0;    // 費半 >+3% 視為強催化
    private const TX_STRONG_THRESHOLD = 1.5;      // 台指期 >+1.5% 視為強催化
    private const SOX_WEAK_THRESHOLD = -3.0;      // 費半 <-3% 視為恐慌
    private const TX_WEAK_THRESHOLD = -1.5;       // 台指期 <-1.5% 視為恐慌

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
     * }
     */
    public static function detect(string $tradeDate): array
    {
        $indices = UsMarketIndex::where('date', $tradeDate)->get();

        $sox = $indices->firstWhere('symbol', '^SOX');
        $tx = $indices->firstWhere('symbol', 'TX');
        $sp500 = $indices->firstWhere('symbol', '^GSPC');
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

            // NASDAQ 大漲也加入觸發
            $nasdaqChange = $nasdaq ? (float) $nasdaq->change_percent : null;
            if ($nasdaqChange !== null && $nasdaqChange >= 2.0) {
                $triggers[] = "那斯達克+{$nasdaqChange}%";
            }

            $result = array_merge($base, [
                'label' => self::CONTEXT_BULLISH_CATALYST,
                'triggers' => $triggers,
                'hint' => self::buildBullishHint($triggers, $beneficiaries),
                'beneficiary_industries' => $beneficiaries,
            ]);

            Log::info('MarketContext: 利多催化日 — ' . implode(', ', $triggers));
            return $result;
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

            $result = array_merge($base, [
                'label' => self::CONTEXT_BEARISH_PANIC,
                'triggers' => $triggers,
                'hint' => '國際利空衝擊，開盤可能跳空下殺。收緊選股標準、降低倉位、提高進場門檻。'
                    . '避免逆勢做多弱勢股，僅考慮超強勢個股的抗跌反彈。',
                'beneficiary_industries' => [],
            ]);

            Log::info('MarketContext: 利空恐慌日 — ' . implode(', ', $triggers));
            return $result;
        }

        // 常態
        return array_merge($base, [
            'label' => self::CONTEXT_NORMAL,
            'triggers' => [],
            'hint' => '',
            'beneficiary_industries' => [],
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
