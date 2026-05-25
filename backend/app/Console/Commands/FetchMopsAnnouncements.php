<?php

namespace App\Console\Commands;

use App\Models\NewsArticle;
use App\Services\NewsIndustryMap;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 抓取上市櫃「每日重大訊息」(MOPS)，存為 source=mops 的 NewsArticle，
 * 自動流入 news:compute-indices 的 Haiku 情緒分析與 research() 論點生成。
 *
 * 重訊自帶公司代號 + 結構化主旨/說明，是一手、個股層級的訊號，
 * 補 cnyes 綜合新聞在「個股早期訊號」上的弱項。為當日快照端點：
 * 只回當日出表的重訊，每天抓累積、無法回補歷史。
 */
class FetchMopsAnnouncements extends Command
{
    protected $signature = 'news:fetch-mops {date?}';
    protected $description = '抓取上市櫃每日重大訊息(MOPS)並存為 NewsArticle(source=mops)';

    /** 上市/上櫃欄位名不同，code/name 各自對應；其餘欄位（主旨/說明/發言日期/發言時間）兩市一致 */
    private const SOURCES = [
        ['market' => '上市', 'url' => 'https://openapi.twse.com.tw/v1/opendata/t187ap04_L', 'code' => '公司代號', 'name' => '公司名稱'],
        ['market' => '上櫃', 'url' => 'https://www.tpex.org.tw/openapi/v1/mopsfin_t187ap04_O', 'code' => 'SecuritiesCompanyCode', 'name' => 'CompanyName'],
    ];

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        $this->info("抓取重大訊息: {$date}");

        $total = 0;
        foreach (self::SOURCES as $src) {
            $count = $this->fetchMarket($src, $date);
            $this->info("  {$src['market']}: {$count} 則");
            $total += $count;
            usleep(300_000);
        }

        $time = now()->format('H:i');
        app(TelegramService::class)->broadcast("✅ *重大訊息抓取*({$time}) 完成\n📅 {$date} | 新增 {$total} 則", 'system');

        $this->info("重大訊息抓取完成，新增 {$total} 則");
        return self::SUCCESS;
    }

    private function fetchMarket(array $src, string $date): int
    {
        try {
            $resp = Http::timeout(30)->get($src['url']);
            if (!$resp->successful()) {
                Log::warning("FetchMopsAnnouncements {$src['market']} HTTP {$resp->status()}");
                return 0;
            }
            $rows = $resp->json();
        } catch (\Throwable $e) {
            Log::error("FetchMopsAnnouncements {$src['market']}: " . $e->getMessage());
            return 0;
        }

        if (!is_array($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row[$src['code']] ?? ''));
            $name = trim((string) ($row[$src['name']] ?? ''));
            // 上市端點「主旨」key 帶尾隨空格，兩種都試
            $subject = trim((string) ($row['主旨'] ?? $row['主旨 '] ?? ''));
            $detail = trim((string) ($row['說明'] ?? ''));
            if ($code === '' || $subject === '') {
                continue;
            }

            $title = mb_substr("{$name}({$code}) {$subject}", 0, 500);

            // 重訊發布後內容固定：已抓過就跳過，避免覆蓋 compute-indices 已優化的 industry/情緒
            $article = NewsArticle::firstOrNew([
                'source' => 'mops',
                'title' => $title,
                'fetched_date' => $date,
            ]);
            if ($article->exists) {
                continue;
            }

            $fullText = "{$title} {$detail}";
            $article->fill([
                'summary' => mb_substr($subject, 0, 2000),
                'content' => mb_substr($detail, 0, 12000),
                'content_fetched_at' => now(),
                'category' => 'tw_stock',
                // industry 初值用關鍵字 classify（值域對齊 NewsIndustryMap）；Haiku 在 compute-indices 會語義優化
                'industry' => NewsIndustryMap::classify($fullText),
                'published_at' => $this->parseRocDateTime($row['發言日期'] ?? '', $row['發言時間'] ?? ''),
                // 留空情緒欄位 → 走 news:compute-indices 既有 whereNull('sentiment_score') 路徑自動分析
                'sentiment_score' => null,
                'sentiment_label' => null,
                'ai_analysis' => null,
            ]);
            $article->save();
            $count++;
        }

        return $count;
    }

    /**
     * 民國年日期 (1150524) + 時間 (210422) → Carbon。解析失敗回 null。
     */
    private function parseRocDateTime(?string $rocDate, ?string $time): ?Carbon
    {
        if (!preg_match('/(\d{3})(\d{2})(\d{2})/', (string) $rocDate, $d)) {
            return null;
        }
        $year = (int) $d[1] + 1911;
        $t = str_pad(preg_replace('/\D/', '', (string) $time), 6, '0', STR_PAD_LEFT);

        try {
            return Carbon::create(
                $year, (int) $d[2], (int) $d[3],
                (int) substr($t, 0, 2), (int) substr($t, 2, 2), (int) substr($t, 4, 2),
                'Asia/Taipei'
            );
        } catch (\Throwable) {
            try {
                return Carbon::create($year, (int) $d[2], (int) $d[3], 0, 0, 0, 'Asia/Taipei');
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
