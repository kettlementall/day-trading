<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swing_position_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swing_position_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('close', 10, 2);
            $table->decimal('unrealized_profit_percent', 7, 2)->default(0);
            $table->decimal('current_stop', 10, 2)->nullable();
            $table->decimal('current_target', 10, 2)->nullable();
            $table->unsignedTinyInteger('holding_days')->default(0);
            $table->json('advice')->nullable();
            $table->json('thesis_status')->nullable();
            $table->timestamps();

            $table->unique(['swing_position_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swing_position_snapshots');
    }
};
