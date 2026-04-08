<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->unique();
            $table->string('name', 50);
            $table->string('industry', 50)->nullable();
            $table->string('market', 10)->comment('twse or tpex');
            $table->boolean('is_day_trading')->default(false)->comment('可當沖');
            $table->timestamps();

            $table->index(['market', 'is_day_trading']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
