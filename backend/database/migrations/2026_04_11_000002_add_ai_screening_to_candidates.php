<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->boolean('ai_selected')->default(false)->after('morning_confirmed');
            $table->integer('ai_score_adjustment')->default(0)->after('ai_selected');
            $table->text('ai_reasoning')->nullable()->after('ai_score_adjustment');
            $table->string('intraday_strategy', 50)->nullable()->after('ai_reasoning');
            $table->decimal('reference_support', 10, 2)->nullable()->after('intraday_strategy');
            $table->decimal('reference_resistance', 10, 2)->nullable()->after('reference_support');
            $table->json('ai_warnings')->nullable()->after('reference_resistance');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn([
                'ai_selected', 'ai_score_adjustment', 'ai_reasoning',
                'intraday_strategy', 'reference_support', 'reference_resistance',
                'ai_warnings',
            ]);
        });
    }
};
