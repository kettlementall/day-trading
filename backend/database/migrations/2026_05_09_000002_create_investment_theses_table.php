<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_theses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120)->unique();
            $table->text('description');
            $table->json('industry_chain')->nullable();
            $table->json('beneficiary_industries')->nullable();
            $table->json('beneficiary_keywords')->nullable();
            $table->text('evidence_summary')->nullable();
            $table->json('risk_factors')->nullable();
            $table->string('sentiment_divergence', 40)->nullable();
            $table->unsignedTinyInteger('confidence_score')->default(50);
            $table->string('status', 20)->default('active')->comment('active/inactive/disabled');
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'confidence_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_theses');
    }
};
