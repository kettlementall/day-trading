<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SwingNewsRiskStats extends Command
{
    protected $signature = 'stock:swing-news-risk-stats {--days=60} {--notify}';
    protected $description = '統計 swing ai_selected 候選帶 news_risk vs 不帶的紙上 forward 績效，校驗單檔新聞風險訊號是否有預測力';

    private const RISK_TYPES = [
        'margin_pressure', 'earnings_quality', 'guidance_uncertainty',
        'order_delay', 'cost_pressure', 'event_risk',
    ];

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $cutoffLatest = Carbon::today()->subDays(5)->toDateString();
        $cutoffEarliest = Carbon::today()->subDays($days)->toDateString();

        $candidates = Candidate::query()
            ->where('mode', 'swing')
            ->where('ai_selected', true)
            ->whereBetween('trade_date', [$cutoffEarliest, $cutoffLatest])
            ->orderBy('trade_date')
            ->get();

        if ($candidates->isEmpty()) {
            $this->warn("區間 {$cutoffEarliest} ~ {$cutoffLatest} 無 swing 候選樣本。");
            return self::SUCCESS;
        }

        $rows = $candidates->map(fn (Candidate $c) => $this->buildRow($c))->filter()->values();
        $usable = $rows->filter(fn (array $r) => $r['forward_5d_max'] !== null);
        $withRiskField = $rows->filter(fn (array $r) => $r['has_risk_field']);

        $this->line(sprintf(
            '原始候選 %d 檔｜帶 news_risk 欄位 %d 檔｜完整 5 日 forward %d 檔',
            $rows->count(),
            $withRiskField->count(),
            $usable->count()
        ));

        if ($withRiskField->isEmpty()) {
            $this->warn('區間內無任何候選有 news_risk 欄位，資料尚未累積（feature 上線後新進候選才會有）。');
            return self::SUCCESS;
        }

        if ($usable->isEmpty()) {
            $this->warn('樣本中無任何檔有完整 5 日 forward 報價，需等更多交易日累積。');
            return self::SUCCESS;
        }

        $this->renderOverview($usable, $cutoffEarliest, $cutoffLatest);
        $this->renderRiskTypeBreakdown($usable);

        if ($this->option('notify')) {
            $this->sendTelegram($usable, $cutoffEarliest, $cutoffLatest);
        }

        return self::SUCCESS;
    }

    private function buildRow(Candidate $c): ?array
    {
        $entry = (float) $c->suggested_buy;
        if ($entry <= 0) {
            return null;
        }

        $newsRisk = data_get($c->swing_entry_plan, 'news_risk');
        $hasRisk = (bool) data_get($newsRisk, 'has_short_term_risk');
        $riskType = $hasRisk ? (string) (data_get($newsRisk, 'risk_type') ?: 'unknown') : null;

        $forward = DailyQuote::where('stock_id', $c->stock_id)
            ->where('date', '>', $c->trade_date)
            ->orderBy('date')
            ->limit(20)
            ->get();

        $forward5 = $forward->take(5);
        $forward20 = $forward->take(20);

        return [
            'candidate_id' => $c->id,
            'stock_id' => $c->stock_id,
            'trade_date' => $c->trade_date?->toDateString(),
            'entry' => $entry,
            'has_risk_field' => $newsRisk !== null,
            'has_risk' => $hasRisk,
            'risk_type' => $riskType,
            'forward_5d_max' => $this->maxReturnPct($forward5, $entry, 5),
            'forward_5d_close' => $this->closeReturnPct($forward5, $entry, 5),
            'forward_20d_max' => $this->maxReturnPct($forward20, $entry, 15),
            'forward_20d_close' => $this->closeReturnPct($forward20, $entry, 15),
        ];
    }

    private function maxReturnPct(Collection $quotes, float $entry, int $minBars): ?float
    {
        if ($quotes->count() < $minBars) {
            return null;
        }

        $max = $quotes->max(fn (DailyQuote $q) => (float) $q->high);
        return round(($max - $entry) / $entry * 100, 2);
    }

    private function closeReturnPct(Collection $quotes, float $entry, int $minBars): ?float
    {
        if ($quotes->count() < $minBars) {
            return null;
        }

        $last = (float) $quotes->last()->close;
        return round(($last - $entry) / $entry * 100, 2);
    }

    private function renderOverview(Collection $rows, string $from, string $to): void
    {
        $this->info("═══ Swing news_risk 紙上績效 ═══");
        $this->line("區間 {$from} ~ {$to}（{$rows->count()} 檔有完整 5 日 forward）");

        $withRisk = $rows->where('has_risk', true);
        $withoutRisk = $rows->where('has_risk', false);

        $this->table(
            ['分組', '樣本', 'fwd5d_max%', 'fwd5d_close%', 'fwd20d_max%', 'fwd20d_close%'],
            [
                $this->groupRow('no_news_risk', $withoutRisk),
                $this->groupRow('has_news_risk', $withRisk),
            ]
        );

        if ($withRisk->isNotEmpty() && $withoutRisk->isNotEmpty()) {
            $diff5 = $this->avg($withoutRisk, 'forward_5d_close') - $this->avg($withRisk, 'forward_5d_close');
            $diff20 = $this->avg($withoutRisk, 'forward_20d_close') - $this->avg($withRisk, 'forward_20d_close');
            $this->line(sprintf(
                '差距：5d close 無風險組 - 有風險組 = %+.2f%%；20d close = %+.2f%%（正值代表訊號有預測力）',
                $diff5,
                $diff20
            ));
        }
    }

    private function renderRiskTypeBreakdown(Collection $rows): void
    {
        $withRisk = $rows->where('has_risk', true);
        if ($withRisk->isEmpty()) {
            $this->warn('區間內無任何帶 news_risk 的候選，無法 by risk_type 拆解。');
            return;
        }

        $this->line('');
        $this->info('═══ By risk_type ═══');

        $tableRows = [];
        $byType = $withRisk->groupBy('risk_type');
        foreach (self::RISK_TYPES as $type) {
            $group = $byType->get($type, collect());
            if ($group->isEmpty()) {
                $tableRows[] = [$type, 0, '—', '—', '—', '—'];
                continue;
            }
            $tableRows[] = $this->groupRow($type, $group);
        }

        $unknown = $byType->get('unknown', collect());
        if ($unknown->isNotEmpty()) {
            $tableRows[] = $this->groupRow('unknown', $unknown);
        }

        $this->table(
            ['risk_type', '樣本', 'fwd5d_max%', 'fwd5d_close%', 'fwd20d_max%', 'fwd20d_close%'],
            $tableRows
        );
    }

    private function groupRow(string $label, Collection $group): array
    {
        if ($group->isEmpty()) {
            return [$label, 0, '—', '—', '—', '—'];
        }

        return [
            $label,
            $group->count(),
            $this->fmt($this->avg($group, 'forward_5d_max')),
            $this->fmt($this->avg($group, 'forward_5d_close')),
            $this->fmt($this->avg($group, 'forward_20d_max')),
            $this->fmt($this->avg($group, 'forward_20d_close')),
        ];
    }

    private function avg(Collection $rows, string $key): float
    {
        $values = $rows->pluck($key)->filter(fn ($v) => $v !== null);
        if ($values->isEmpty()) {
            return 0.0;
        }
        return (float) $values->avg();
    }

    private function fmt(float $value): string
    {
        return sprintf('%+.2f', $value);
    }

    private function sendTelegram(Collection $rows, string $from, string $to): void
    {
        $withRisk = $rows->where('has_risk', true);
        $withoutRisk = $rows->where('has_risk', false);

        $lines = [
            "📊 *Swing news\\_risk 績效校驗*",
            "📅 {$from} ~ {$to}",
            "樣本：總 {$rows->count()} 檔｜帶風險 {$withRisk->count()}｜無風險 {$withoutRisk->count()}",
            '',
            sprintf(
                '無風險 5d close=%s%% / 20d close=%s%%',
                $this->fmt($this->avg($withoutRisk, 'forward_5d_close')),
                $this->fmt($this->avg($withoutRisk, 'forward_20d_close'))
            ),
            sprintf(
                '帶風險 5d close=%s%% / 20d close=%s%%',
                $this->fmt($this->avg($withRisk, 'forward_5d_close')),
                $this->fmt($this->avg($withRisk, 'forward_20d_close'))
            ),
        ];

        if ($withRisk->isNotEmpty() && $withoutRisk->isNotEmpty()) {
            $diff5 = $this->avg($withoutRisk, 'forward_5d_close') - $this->avg($withRisk, 'forward_5d_close');
            $lines[] = sprintf('差距 5d=%+.2f%%（正值=訊號有預測力）', $diff5);
        }

        app(TelegramService::class)->broadcast(implode("\n", $lines), 'system');
    }
}
