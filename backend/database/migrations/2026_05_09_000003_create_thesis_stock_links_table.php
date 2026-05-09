<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thesis_stock_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_thesis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('relevance_score')->default(0);
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->unique(['investment_thesis_id', 'stock_id']);
            $table->index(['stock_id', 'relevance_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thesis_stock_links');
    }
};
