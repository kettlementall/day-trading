<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 加入 is_swing_eligible 旗標，把短線（1-4 週）股票池跟當沖名單解耦。
 *
 * 動機：原本 SwingScreenerService 寫死 Stock::where('is_day_trading', true)，
 * 等於把短線選股綁死在當沖名單上，會錯過 ETF、未開放當沖的成長股、處置但基本面健康的股。
 * 由 stock:refresh-swing-universe 指令依「成交量、價格、資料完整度、ETF 類型」自動重算。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->boolean('is_swing_eligible')
                ->default(false)
                ->after('is_day_trading')
                ->comment('短線（1-4週）選股池，由 stock:refresh-swing-universe 重算');
            $table->index('is_swing_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropIndex(['is_swing_eligible']);
            $table->dropColumn('is_swing_eligible');
        });
    }
};
