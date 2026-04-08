<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_rounds', function (Blueprint $table) {
            $table->id();
            $table->date('analyzed_from');
            $table->date('analyzed_to');
            $table->unsignedInteger('sample_count');
            $table->json('metrics_before');
            $table->json('metrics_after')->nullable();
            $table->json('suggestions');
            $table->boolean('applied')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_rounds');
    }
};
