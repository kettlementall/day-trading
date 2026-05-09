<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swing_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('holding')->comment('watching/holding/exit_suggested/closed/stopped');
            $table->decimal('entry_price', 10, 2);
            $table->unsignedInteger('shares')->default(0);
            $table->date('entry_date');
            $table->decimal('current_stop', 10, 2)->nullable();
            $table->decimal('current_target', 10, 2)->nullable();
            $table->unsignedTinyInteger('max_holding_days')->default(20);
            $table->decimal('exit_price', 10, 2)->nullable();
            $table->date('exit_date')->nullable();
            $table->json('latest_advice')->nullable();
            $table->json('advice_log')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['stock_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swing_positions');
    }
};
