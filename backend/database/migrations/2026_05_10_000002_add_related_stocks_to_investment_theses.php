<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_theses', function (Blueprint $table) {
            $table->json('related_stocks')
                ->nullable()
                ->after('beneficiary_keywords')
                ->comment('AI 產業論點映射到個股的角色與受益層級');
        });
    }

    public function down(): void
    {
        Schema::table('investment_theses', function (Blueprint $table) {
            $table->dropColumn('related_stocks');
        });
    }
};
