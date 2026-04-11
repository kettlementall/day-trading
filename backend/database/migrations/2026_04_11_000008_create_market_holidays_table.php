<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 100)->comment('假日名稱');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_holidays');
    }
};
