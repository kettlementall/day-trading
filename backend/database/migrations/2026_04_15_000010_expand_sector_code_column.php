<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sector_indices', function (Blueprint $table) {
            $table->string('sector_code', 60)->comment('TWSE 類股指數名稱，如 半導體類指數')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sector_indices', function (Blueprint $table) {
            $table->string('sector_code', 10)->change();
        });
    }
};
