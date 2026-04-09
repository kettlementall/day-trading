#!/bin/bash
# 重跑 3/10 以後的候選標的 + 結果回填 + 顯示指標
# 用法: docker compose exec php bash rescreen.sh

php artisan tinker --execute="
App\Models\CandidateResult::query()->delete();
App\Models\Candidate::query()->delete();
echo 'Cleared';
"

dates=$(php artisan tinker --execute="
\$dates = App\Models\DailyQuote::whereBetween('date', ['2026-03-10', '2026-04-09'])
    ->selectRaw('DATE(date) as d')->distinct()->orderBy('d')->pluck('d');
echo \$dates->implode(' ');
")

for d in $dates; do
  php artisan stock:screen-candidates "$d" 2>&1 | grep "篩選完成"
  php artisan stock:update-results "$d" 2>&1
done

echo ""
echo "=== 回測指標 ==="
php artisan tinker --execute="
\$m = (new App\Services\BacktestService())->computeMetrics('2026-03-10', '2026-04-09');
echo '候選數: ' . \$m['total_candidates'] . ' | 每日: ' . (\$m['screening']['candidates_per_day'] ?? 0) . PHP_EOL;
echo '買入可達率: ' . \$m['buy_reach_rate'] . '%' . PHP_EOL;
echo '目標可達率: ' . \$m['target_reach_rate'] . '%' . PHP_EOL;
echo '雙達率: ' . \$m['dual_reach_rate'] . '%' . PHP_EOL;
echo '期望值: ' . \$m['expected_value'] . '%' . PHP_EOL;
echo '停損率: ' . \$m['hit_stop_loss_rate'] . '%' . PHP_EOL;
echo '風報比: ' . \$m['avg_risk_reward'] . PHP_EOL;
"
