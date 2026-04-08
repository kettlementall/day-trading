<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->onDelete('cascade');
            $table->decimal('actual_open', 10, 2)->comment('實際開盤價');
            $table->decimal('actual_high', 10, 2)->comment('實際最高價');
            $table->decimal('actual_low', 10, 2)->comment('實際最低價');
            $table->decimal('actual_close', 10, 2)->comment('實際收盤價');
            $table->boolean('hit_target')->default(false)->comment('是否達到目標價');
            $table->boolean('hit_stop_loss')->default(false)->comment('是否觸及停損');
            $table->decimal('max_profit_percent', 6, 2)->default(0)->comment('最大獲利%');
            $table->decimal('max_loss_percent', 6, 2)->default(0)->comment('最大虧損%');
            $table->timestamps();

            $table->unique('candidate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_results');
    }
};
