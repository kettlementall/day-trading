<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swing_positions', function (Blueprint $table) {
            $table->unsignedInteger('realized_exit_shares')->default(0)->after('exit_price');
            $table->decimal('realized_exit_value', 16, 2)->default(0)->after('realized_exit_shares');
        });
    }

    public function down(): void
    {
        Schema::table('swing_positions', function (Blueprint $table) {
            $table->dropColumn(['realized_exit_shares', 'realized_exit_value']);
        });
    }
};
