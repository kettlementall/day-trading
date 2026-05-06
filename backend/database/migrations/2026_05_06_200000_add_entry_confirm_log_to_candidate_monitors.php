<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 加入 entry_confirm_log 欄位，獨立於 ai_advice_log。
 *
 * 用於記錄「規則層觸發進場時 AI 即時確認結果」的決策歷史。
 * 刻意獨立於 ai_advice_log：避免下游 (rolling advice / emergencyAdvice /
 * BacktestService / DailyReviewService) 讀 ai_advice_log 時混到非 rolling 內容。
 *
 * 動機：5/6 3481 群創在 AI 連兩輪明確警告「謹慎」「不宜強追」之後，
 * 規則層 evaluateWatching 仍因 isPullbackEntry() 觸發而搶進，3 分鐘後
 * AI 立刻 exit。新增 confirmRuleEntry 機制，規則層觸發前必須通過 AI 即時確認。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('candidate_monitors', function (Blueprint $table) {
            $table->json('entry_confirm_log')->nullable()->after('ai_advice_log');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_monitors', function (Blueprint $table) {
            $table->dropColumn('entry_confirm_log');
        });
    }
};
