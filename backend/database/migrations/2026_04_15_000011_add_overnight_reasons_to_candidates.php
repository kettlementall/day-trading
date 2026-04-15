<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->text('overnight_news_reason')->nullable()->after('overnight_reasoning');
            $table->text('overnight_fundamental_reason')->nullable()->after('overnight_news_reason');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['overnight_news_reason', 'overnight_fundamental_reason']);
        });
    }
};
