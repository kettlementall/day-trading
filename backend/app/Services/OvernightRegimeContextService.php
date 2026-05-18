<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class OvernightRegimeContextService
{
    private const INDICES = [
        'IX0001' => ['name' => '加權指數', 'group' => 'market'],
        'IX0043' => ['name' => '櫃買指數', 'group' => 'market'],
        'IX0027' => ['name' => '電子工業', 'group' => 'sector'],
        'IX0028' => ['name' => '半導體業', 'group' => 'sector'],
        'IX0029' => ['name' => '電腦及週邊設備業', 'group' => 'sector'],
        'IX0030' => ['name' => '光電業', 'group' => 'sector'],
        'IX0031' => ['name' => '通信網路業', 'group' => 'sector'],
    ];

    public function __construct(private FugleRealtimeClient $fugle)
    {
    }

    public function getContext(): array
    {
        try {
            $quotes = $this->fugle->fetchRawQuotesBySymbols(array_keys(self::INDICES));
            $rows = [];

            foreach (self::INDICES as $symbol => $meta) {
                if (!isset($quotes[$symbol])) {
                    continue;
                }

                $parsed = self::parseIndexQuote($symbol, $meta, $quotes[$symbol]);
                if ($parsed) {
                    $rows[] = $parsed;
                }
            }

            if (empty($rows)) {
                return [
                    'available' => false,
                    'reason' => 'fugle_no_index_quotes',
                    'rows' => [],
                    'prompt' => '即時大盤/類股資料不可用；Final Ranking 不得因此硬排除標的。',
                ];
            }

            return [
                'available' => true,
                'reason' => null,
                'rows' => $rows,
                'prompt' => self::buildPrompt($rows),
            ];
        } catch (\Throwable $e) {
            Log::warning('OvernightRegimeContextService failed: ' . $e->getMessage());

            return [
                'available' => false,
                'reason' => 'exception',
                'rows' => [],
                'prompt' => '即時大盤/類股資料抓取失敗；Final Ranking 不得因此硬排除標的。',
            ];
        }
    }

    public static function parseIndexQuote(string $symbol, array $meta, array $quote): ?array
    {
        $current = (float) ($quote['closePrice'] ?? $quote['lastPrice'] ?? $quote['price'] ?? 0);
        $reference = (float) ($quote['referencePrice'] ?? 0);

        $changePercent = $quote['changePercent'] ?? null;
        if ($changePercent === null && $current > 0 && $reference > 0) {
            $changePercent = round(($current - $reference) / $reference * 100, 2);
        }

        if ($current <= 0 && $changePercent === null) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'name' => $quote['name'] ?? $meta['name'],
            'group' => $meta['group'],
            'price' => $current > 0 ? $current : null,
            'change_percent' => $changePercent !== null ? round((float) $changePercent, 2) : null,
            'updated_at' => $quote['date'] ?? $quote['lastUpdated'] ?? null,
        ];
    }

    public static function buildPrompt(array $rows): string
    {
        $lines = collect($rows)
            ->map(function (array $row) {
                $change = $row['change_percent'];
                $changeText = $change === null ? '漲跌不明' : (($change >= 0 ? '+' : '') . $change . '%');
                return "- {$row['name']}({$row['symbol']}): {$changeText}";
            })
            ->implode("\n");

        $sectors = collect($rows)->where('group', 'sector')->whereNotNull('change_percent')->sortByDesc('change_percent')->values();
        $top = $sectors->take(3)->map(fn ($row) => $row['name'])->implode('、') ?: '無';
        $weakCount = $sectors->filter(fn ($row) => (float) $row['change_percent'] < 0)->count();

        return <<<TEXT
## 即時大盤/類股 Regime（Fugle realtime）
{$lines}

Final Ranking 使用方式：
- 這是跨標的比較 context，不是硬規則。
- 若大盤/櫃買/主流電子偏弱，普通 open_follow_through 需要更強尾盤、量能與抗跌證據。
- 若強勢集中在少數類股，優先比較該主流類股中的領先個股，但不可只因非主流就機械排除。
- 目前較強類股：{$top}；下跌類股數：{$weakCount}。
TEXT;
    }
}
