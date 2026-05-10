<?php

namespace App\Services;

use App\Models\InvestmentThesis;
use App\Models\DailyQuote;
use App\Models\NewsArticle;
use App\Models\NewsIndex;
use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class InvestmentThesisResearchService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.screening_model', 'claude-opus-4-6');
    }

    public function research(string $date): array
    {
        $from = now()->parse($date)->subDays(14)->toDateString();

        $articles = NewsArticle::whereBetween('fetched_date', [$from, $date])
            ->whereNotNull('sentiment_score')
            ->orderByDesc('published_at')
            ->limit(80)
            ->get();

        $indices = NewsIndex::whereBetween('date', [$from, $date])
            ->orderByDesc('date')
            ->limit(20)
            ->get();

        $items = $this->apiKey
            ? $this->askAi($date, $articles, $indices)
            : $this->fallbackTheses($articles);

        $saved = 0;
        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $confidence = max(0, min(100, (int) ($item['confidence_score'] ?? 55)));
            $status = $confidence < 35 ? InvestmentThesis::STATUS_INACTIVE : InvestmentThesis::STATUS_ACTIVE;

            $existing = InvestmentThesis::where('title', $title)->first();
            if ($existing?->status === InvestmentThesis::STATUS_DISABLED) {
                continue;
            }
            $relatedStocks = $this->normalizeRelatedStocks($item['related_stocks'] ?? []);

            InvestmentThesis::updateOrCreate(
                ['title' => $title],
                [
                    'description' => (string) ($item['description'] ?? ''),
                    'industry_chain' => $item['industry_chain'] ?? [],
                    'beneficiary_industries' => $item['beneficiary_industries'] ?? [],
                    'beneficiary_keywords' => $item['beneficiary_keywords'] ?? [],
                    'related_stocks' => $relatedStocks,
                    'evidence_summary' => (string) ($item['evidence_summary'] ?? ''),
                    'risk_factors' => $item['risk_factors'] ?? [],
                    'sentiment_divergence' => $item['sentiment_divergence'] ?? null,
                    'research_date' => $date,
                    'confidence_score' => $confidence,
                    'status' => $status,
                    'last_evaluated_at' => now(),
                ]
            );
            $saved++;
        }

        $this->decayStaleTheses();

        return ['saved' => $saved, 'input_articles' => $articles->count()];
    }

    private function askAi(string $date, $articles, $indices): array
    {
        $existingTheses = InvestmentThesis::where('status', '!=', InvestmentThesis::STATUS_DISABLED)
            ->where(function ($q) use ($date) {
                $q->whereNull('research_date')
                    ->orWhere('research_date', '<=', $date);
            })
            ->orderByDesc('confidence_score')
            ->limit(12)
            ->get();

        $industryBriefs = $articles
            ->whereNotNull('industry')
            ->groupBy('industry')
            ->map(function ($group, $industry) {
                $avg = round((float) $group->avg('sentiment_score'), 1);
                $positive = $group->where('sentiment_score', '>', 20)->count();
                $negative = $group->where('sentiment_score', '<', -20)->count();
                $titles = $group->sortByDesc('published_at')->take(8)->pluck('title')->values()->all();

                return [
                    'industry' => $industry,
                    'article_count' => $group->count(),
                    'avg_sentiment' => $avg,
                    'positive_count' => $positive,
                    'negative_count' => $negative,
                    'representative_news' => $titles,
                ];
            })
            ->sortByDesc('article_count')
            ->values()
            ->take(12);

        $newsLines = $articles->map(fn ($a) =>
            "- [{$a->industry}/{$a->sentiment_label}/impact=" . ($a->ai_analysis['impact'] ?? '-') . "] {$a->title}" . ($a->summary ? " — " . mb_substr($a->summary, 0, 180) : '')
        )->implode("\n");
        $indexLines = $indices->map(fn ($i) =>
            "{$i->date->format('Y-m-d')} {$i->scope}:{$i->scope_value} 情緒{$i->sentiment} 熱度{$i->heatmap} 恐慌{$i->panic}"
        )->implode("\n");
        $existingLines = $existingTheses->map(fn ($t) =>
            "- {$t->title} | status={$t->status} | confidence={$t->confidence_score} | {$t->description}"
        )->implode("\n");
        $stockUniverse = $this->buildStockUniverse($industryBriefs);
        $stockUniverseText = $stockUniverse->map(fn (Stock $stock) =>
            "{$stock->symbol} {$stock->name} {$stock->industry}"
        )->implode("\n");
        $industryJson = json_encode($industryBriefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $prompt = <<<PROMPT
你是台股「理專型」短線產業研究員。你的任務不是整理新聞分類，而是提出 1-4 週可交易的**產業鏈投資論點**。

你必須做深層推理：
1. 找出需求來源或政策/總經驅動：例如 AI capex、資料中心、記憶體週期、匯率、庫存循環、政策補助。
2. 推導一階受益：直接訂單或報價受益的環節。
3. 推導二階/三階受益：材料、設備、封測、散熱、PCB、電源、通路、替代供應鏈。
4. 判斷台股哪些產業或股票名稱關鍵字可能受益。
5. 點名台股可能受惠個股，並說清楚每檔在產業鏈中的角色。點名不是買進建議，只是供短線配置加權。
6. 寫出論點失效條件：報價反轉、capex 下修、庫存惡化、法人轉賣、技術跌破等。

禁止事項：
- 不可以用「半導體 短線題材延續」「AI與雲端 短線題材延續」這種粗分類當 title。
- 不可以只重述新聞標題。
- 不可以把同一條供應鏈拆成多個高度重複論點。
- 不可以產生沒有台股可映射受益環節的空泛總經論點。
- 不可以根據既有短線候選、持倉、曾經選出的股票去反推產業論點或個股名單；related_stocks 只能來自新聞、產業鏈推理與你對台股供應鏈的判斷。

日期：{$date}

既有論點（請更新、合併或淘汰；不要無意義重複）：
{$existingLines}

分產業摘要 JSON：
{$industryJson}

可點名股票 universe（只能從這份清單選 related_stocks；不得使用短線候選、持倉或清單外股票）：
{$stockUniverseText}

新聞明細：
{$newsLines}

產業/新聞指數：
{$indexLines}

# 輸出規範（嚴格遵守）

只輸出 JSON 陣列，最多 6 筆，不要有任何解釋文字、不要包裹 markdown code block。
每筆物件**必須**包含下列所有欄位（欄位名稱完全一致，不可改成 sector/horizon/direction 等別名）：

- `title` (string)：具體論點名稱，必須包含驅動與受益鏈，例：「AI 伺服器升級帶動 HBM/PCB/散熱鏈」
- `description` (string)：核心敘事（2-3 句），必須包含「驅動因素 → 受益環節 → 台股映射 → 短線催化」
- `industry_chain` (string[])：至少 4 個上中下游節點，例 ["雲端資本支出", "AI GPU", "HBM/DRAM", "CoWoS/封測", "高階PCB", "散熱"]
- `beneficiary_industries` (string[])：受惠產業類別（用以對應 stocks.industry 欄位），例 ["半導體", "電子零組件"]
- `beneficiary_keywords` (string[])：股票名稱/個股關鍵字/供應鏈詞，用以對 Stock.name 或產業字串匹配，至少 8 個，例 ["台積電", "日月光", "華通", "台光電", "雙鴻", "奇鋐", "HBM", "PCB"]
- `related_stocks` (object[])：此論點明確映射到的台股個股，最多 6 檔；每檔必須有 symbol/name/benefit_level/role/reasoning/confidence/risks。benefit_level 只能是 core、secondary、watch；core 最多 3 檔。只能從「可點名股票 universe」選，且不得只因名稱相似就列入，必須說明供應鏈角色。
- `evidence_summary` (string)：佐證新聞重點摘要；要指出哪些新聞支持需求、報價、訂單、法人或情緒
- `risk_factors` (string[])：至少 4 個風險，包含「論點失效條件」
- `sentiment_divergence` (string)：必須為這三個值之一 — `none` / `bullish_fundamental_bearish_sentiment` / `bearish_fundamental_bullish_sentiment`
- `confidence_score` (整數，0-100)：信心分。建議分布：> 80 信心極強的最多 2 筆；60-80 主流 4-5 筆；< 60 列觀察。

範例（僅示範格式，請依新聞實際內容產生）：
[
  {
    "title": "AI 伺服器升級帶動 HBM/PCB/散熱鏈",
    "description": "雲端業者資本支出與 AI 推論需求推升 AI server 出貨，先拉動 GPU/HBM 與先進封裝，再外溢到高階 PCB、CCL、散熱與電源。台股可映射到記憶體、封測、PCB、散熱模組與伺服器零組件。短線催化來自新聞熱度、法人買超與技術面突破。",
    "industry_chain": ["雲端資本支出", "AI GPU", "HBM/DRAM", "CoWoS/封測", "高階PCB/CCL", "散熱/電源"],
    "beneficiary_industries": ["半導體", "電子零組件"],
    "beneficiary_keywords": ["台積電", "TSMC", "日月光", "華通", "台光電", "雙鴻"],
    "related_stocks": [
      {
        "symbol": "2313",
        "name": "華通",
        "benefit_level": "core",
        "role": "高階 PCB 供應鏈",
        "reasoning": "AI server 規格升級會拉動高階 PCB 層數與單價，華通具 PCB 供應鏈映射。",
        "confidence": 78,
        "risks": ["AI server 出貨遞延", "PCB 報價不如預期"]
      }
    ],
    "evidence_summary": "10/30 NVDA 法說上修；華通 11/5 公告 N3 PCB 出貨；台光電 CCL 連 5 月成長",
    "risk_factors": ["美中對 AI 晶片出口管制", "下游雲端資本支出放緩", "HBM 報價轉弱", "股價跌破月線且法人轉賣"],
    "sentiment_divergence": "none",
    "confidence_score": 85
  }
]
PROMPT;

        try {
            $response = Http::timeout(240)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 16000,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::error('InvestmentThesisResearch API error: ' . $response->body());
                return $this->fallbackTheses($articles);
            }

            $stopReason = $response->json('stop_reason');
            if ($stopReason === 'max_tokens') {
                Log::warning('InvestmentThesisResearch: hit max_tokens limit, response truncated');
            }

            $text = $response->json('content.0.text', '');
            // Robust JSON array extraction: 不論 AI 是否包 markdown / 有前後文字，
            // 只取第一個 [ 到最後一個 ] 之間（過去 preg_replace 會把整段吃掉）。
            $start = strpos($text, '[');
            $end = strrpos($text, ']');
            if ($start === false || $end === false || $end <= $start) {
                Log::warning('InvestmentThesisResearch: no JSON array brackets found, snippet=' . mb_substr(trim($text), 0, 200));
                return $this->fallbackTheses($articles);
            }
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
            if (!is_array($data)) {
                Log::warning('InvestmentThesisResearch: JSON decode failed, snippet=' . mb_substr(trim($text), 0, 200));
                return $this->fallbackTheses($articles);
            }
            $valid = collect($data)->filter(fn ($i) => is_array($i) && !empty($i['title']))->count();
            if ($valid === 0 && count($data) > 0) {
                Log::warning('InvestmentThesisResearch: AI response missing title field, first item keys=' . json_encode(array_keys($data[0] ?? [])));
                return $this->fallbackTheses($articles);
            }
            return $data;
        } catch (\Throwable $e) {
            Log::error('InvestmentThesisResearch: ' . $e->getMessage());
            return $this->fallbackTheses($articles);
        }
    }

    private function fallbackTheses($articles): array
    {
        $groups = $articles->whereNotNull('industry')->groupBy('industry');
        $items = [];

        $themeTemplates = [
            [
                'match' => ['半導體', 'AI與雲端'],
                'title' => 'AI 伺服器升級帶動 HBM/PCB/散熱鏈',
                'description' => 'AI 訓練與推論需求推升雲端資本支出，先帶動 GPU、HBM/DRAM 與先進封裝，再外溢到高階 PCB、CCL、散熱與電源。台股短線可從記憶體、封測、PCB、散熱模組與伺服器零組件尋找技術轉強且法人回補的標的。',
                'industry_chain' => ['雲端資本支出', 'AI GPU', 'HBM/DRAM', 'CoWoS/封測', '高階PCB/CCL', '散熱/電源'],
                'beneficiary_industries' => ['半導體', 'AI與雲端', '電子零組件', '電腦及週邊設備業'],
                'beneficiary_keywords' => ['HBM', 'DRAM', '記憶體', 'CoWoS', '封測', 'PCB', 'CCL', '散熱', '電源', '伺服器', 'AI server'],
                'related_stocks' => [],
                'risk_factors' => ['雲端資本支出下修', 'HBM/DRAM 報價轉弱', '美中出口管制升溫', '股價跌破月線且法人轉賣'],
            ],
            [
                'match' => ['電子零組件', 'AI與雲端'],
                'title' => 'AI 資料中心功耗提升推升電源/散熱/連接器鏈',
                'description' => 'AI server 功耗與機櫃密度提升，迫使資料中心升級散熱、電源供應、連接器與線材規格。若新聞熱度與法人買盤同步轉強，短線可優先觀察具備伺服器供應鏈位置且技術面站回中期均線的零組件股。',
                'industry_chain' => ['AI server', '高功耗機櫃', '電源供應', '液冷/風冷散熱', '高速連接器/線材'],
                'beneficiary_industries' => ['電子零組件', '電腦及週邊設備業', 'AI與雲端'],
                'beneficiary_keywords' => ['散熱', '液冷', '風扇', '電源', '連接器', '線材', '伺服器', '資料中心'],
                'related_stocks' => [],
                'risk_factors' => ['客戶認證延後', '毛利率被成本侵蝕', 'AI server 出貨遞延', '短線漲多後量縮跌破支撐'],
            ],
            [
                'match' => ['金融', '總體經濟'],
                'title' => '利率與匯率環境轉折牽動金融評價修復',
                'description' => '若通膨、利率與匯率新聞顯示金融環境改善，壽險淨值、銀行利差與金融股配息預期可能獲得短線評價修復。短線重點不是追高，而是觀察殖利率支撐、外資回補與技術面底部轉強。',
                'industry_chain' => ['通膨/利率', '債券評價', '壽險淨值', '銀行利差', '金融股評價'],
                'beneficiary_industries' => ['金融', '金融保險'],
                'beneficiary_keywords' => ['金控', '銀行', '壽險', '殖利率', '配息', '利率', '債券'],
                'related_stocks' => [],
                'risk_factors' => ['利率反向急升', '匯損擴大', '金融監理利空', '外資轉賣金融權值'],
            ],
            [
                'match' => ['傳產'],
                'title' => '原物料/運價循環反彈帶動傳產評價修復',
                'description' => '若油價、運價或原物料報價轉強，傳產循環股可能從低基期獲得短線評價修復。此類論點需要同時觀察報價、庫存、法人買盤與股價是否突破長期整理區。',
                'industry_chain' => ['原物料報價', '庫存循環', '產品利差', '傳產獲利', '評價修復'],
                'beneficiary_industries' => ['傳產', '鋼鐵工業', '航運業', '塑膠工業'],
                'beneficiary_keywords' => ['鋼鐵', '航運', '塑化', '水泥', '報價', '運價', '庫存'],
                'related_stocks' => [],
                'risk_factors' => ['報價反彈失敗', '需求復甦不如預期', '庫存去化延後', '跌回整理區間'],
            ],
        ];

        foreach ($themeTemplates as $template) {
            $matched = collect($template['match'])
                ->flatMap(fn ($industry) => $groups->get($industry, collect()))
                ->values();

            if ($matched->count() < 2) {
                continue;
            }

            $avg = (float) $matched->avg('sentiment_score');
            $items[] = [
                'title' => $template['title'],
                'description' => $template['description'],
                'industry_chain' => $template['industry_chain'],
                'beneficiary_industries' => $template['beneficiary_industries'],
                'beneficiary_keywords' => $template['beneficiary_keywords'],
                'evidence_summary' => $matched->take(8)->pluck('title')->implode('；'),
                'risk_factors' => $template['risk_factors'],
                'sentiment_divergence' => $avg < -10 ? 'bullish_fundamental_bearish_sentiment' : 'none',
                'confidence_score' => max(45, min(78, 55 + (int) round($avg / 5) + min(12, $matched->count()))),
            ];
        }

        foreach ($groups as $industry => $group) {
            if ($group->count() < 3) {
                continue;
            }
            if (collect($items)->contains(fn ($item) => in_array($industry, $item['beneficiary_industries'] ?? [], true))) {
                continue;
            }
            $avg = (float) $group->avg('sentiment_score');
            $items[] = [
                'title' => "{$industry} 供需/籌碼轉強短線鏈",
                'description' => "近期 {$industry} 新聞量與情緒具備短線觀察價值，但需進一步確認需求、報價、訂單或政策驅動是否能外溢到台股供應鏈。短線只選技術面站回中期均線、法人未轉賣且估值未明顯過熱的標的。",
                'industry_chain' => ['需求/政策驅動', "{$industry} 訂單或報價", '台股供應鏈', '法人籌碼', '短線技術轉強'],
                'beneficiary_industries' => [$industry],
                'beneficiary_keywords' => collect(NewsIndustryMap::INDUSTRIES[$industry] ?? [$industry])->take(8)->values()->all(),
                'related_stocks' => [],
                'evidence_summary' => $group->take(5)->pluck('title')->implode('；'),
                'risk_factors' => ['新聞題材退燒', '股價已提前反映', '法人轉賣', '跌破月線或關鍵支撐'],
                'sentiment_divergence' => $avg < 0 ? 'bearish_fundamental_bullish_sentiment' : 'none',
                'confidence_score' => max(40, min(75, 50 + (int) round($avg / 4) + min(15, $group->count()))),
            ];
        }

        return array_slice($items, 0, 8);
    }

    private function buildStockUniverse($industryBriefs): Collection
    {
        $industries = collect($industryBriefs)->pluck('industry')->filter()->values();
        $keywords = $industries
            ->flatMap(fn ($industry) => NewsIndustryMap::INDUSTRIES[$industry] ?? [$industry])
            ->merge($industries)
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter(fn ($keyword) => $keyword !== '')
            ->unique()
            ->values();

        return Stock::query()
            ->select('stocks.*')
            ->leftJoin('daily_quotes as latest_quote', function ($join) {
                $join->on('latest_quote.stock_id', '=', 'stocks.id')
                    ->where('latest_quote.date', DailyQuote::max('date'));
            })
            ->where(function ($query) use ($industries, $keywords) {
                foreach ($industries as $industry) {
                    $query->orWhere('industry', 'like', '%' . $industry . '%');
                }
                foreach ($keywords->take(30) as $keyword) {
                    $query->orWhere('industry', 'like', '%' . $keyword . '%')
                        ->orWhere('name', 'like', '%' . $keyword . '%');
                }
            })
            ->orderByDesc('stocks.is_swing_eligible')
            ->orderByDesc('latest_quote.trade_value')
            ->orderBy('stocks.symbol')
            ->limit(100)
            ->get();
    }

    private function normalizeRelatedStocks(array $items): array
    {
        $normalized = collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $symbol = trim((string) ($item['symbol'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                $stock = $symbol !== ''
                    ? Stock::where('symbol', $symbol)->first()
                    : null;

                if (!$stock && $name !== '') {
                    $stock = Stock::where('name', $name)->first();
                }
                if (!$stock) {
                    return null;
                }

                $level = (string) ($item['benefit_level'] ?? 'watch');
                if (!in_array($level, ['core', 'secondary', 'watch'], true)) {
                    $level = 'watch';
                }

                $role = trim((string) ($item['role'] ?? ''));
                $reasoning = trim((string) ($item['reasoning'] ?? ''));
                if ($role === '' || $reasoning === '') {
                    return null;
                }

                return [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'benefit_level' => $level,
                    'role' => $role,
                    'reasoning' => $reasoning,
                    'evidence' => array_values(array_slice((array) ($item['evidence'] ?? []), 0, 5)),
                    'confidence' => max(0, min(100, (int) ($item['confidence'] ?? 50))),
                    'risks' => array_values(array_slice((array) ($item['risks'] ?? []), 0, 5)),
                ];
            })
            ->filter()
            ->unique('symbol')
            ->sortByDesc('confidence')
            ->values();

        $coreCount = 0;
        return $normalized
            ->take(12)
            ->map(function (array $item) use (&$coreCount) {
                if ($item['benefit_level'] === 'core') {
                    $coreCount++;
                    if ($coreCount > 5) {
                        $item['benefit_level'] = 'secondary';
                    }
                }
                return $item;
            })
            ->values()
            ->all();
    }

    private function decayStaleTheses(): void
    {
        InvestmentThesis::where('status', '!=', InvestmentThesis::STATUS_DISABLED)
            ->where(function ($q) {
                $q->whereNull('last_evaluated_at')
                    ->orWhere('last_evaluated_at', '<', now()->subDays(3));
            })
            ->get()
            ->each(function (InvestmentThesis $thesis) {
                $next = max(0, $thesis->confidence_score - 10);
                $thesis->update([
                    'confidence_score' => $next,
                    'status' => $next < 35 ? InvestmentThesis::STATUS_INACTIVE : $thesis->status,
                ]);
            });
    }
}
