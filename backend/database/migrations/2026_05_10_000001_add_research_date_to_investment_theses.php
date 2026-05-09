<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_theses', function (Blueprint $table) {
            $table->date('research_date')
                ->nullable()
                ->after('sentiment_divergence')
                ->comment('論點最後一次使用哪個日期以前的新聞/指數研究或驗證');

            $table->index(['research_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('investment_theses', function (Blueprint $table) {
            $table->dropIndex(['research_date', 'status']);
            $table->dropColumn('research_date');
        });
    }
};
