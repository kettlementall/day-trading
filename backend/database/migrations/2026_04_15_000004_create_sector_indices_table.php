<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sector_indices', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('sector_code', 10)->comment('TWSE 類股代號，如 IX0049');
            $table->string('sector_name', 50)->comment('類股名稱，如 半導體業');
            $table->decimal('index_value', 10, 2)->default(0)->comment('類股指數點位');
            $table->decimal('change_percent', 6, 2)->default(0)->comment('當日漲跌幅%');
            $table->bigInteger('volume')->default(0)->comment('成交量（千股）');
            $table->timestamps();

            $table->unique(['date', 'sector_code']);
            $table->index('date');
            $table->index(['date', 'sector_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sector_indices');
    }
};
