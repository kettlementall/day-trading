<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intraday_snapshots', function (Blueprint $table) {
            $table->boolean('limit_up')->default(false)->after('amplitude_percent');
            $table->boolean('limit_down')->default(false)->after('limit_up');
        });
    }

    public function down(): void
    {
        Schema::table('intraday_snapshots', function (Blueprint $table) {
            $table->dropColumn(['limit_up', 'limit_down']);
        });
    }
};
