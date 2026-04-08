<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50)->comment('來源: cnyes, yahoo, google');
            $table->string('title', 500);
            $table->text('summary')->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('category', 50)->nullable()->comment('分類: tw_stock, international, industry, macro');
            $table->string('industry', 50)->nullable()->comment('產業分類');
            $table->decimal('sentiment_score', 5, 2)->nullable()->comment('情緒分數 -100 ~ 100');
            $table->string('sentiment_label', 20)->nullable()->comment('positive/negative/neutral');
            $table->json('ai_analysis')->nullable()->comment('Claude 分析結果');
            $table->date('fetched_date')->comment('抓取日期');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('fetched_date');
            $table->index('industry');
            $table->index('sentiment_score');
        });

        Schema::create('news_indices', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('scope', 20)->comment('overall 或 industry');
            $table->string('scope_value', 50)->nullable()->comment('產業名稱，overall 時為 null');
            $table->decimal('sentiment', 5, 2)->default(50)->comment('情緒指標 0-100');
            $table->decimal('heatmap', 5, 2)->default(50)->comment('熱度指標 0-100');
            $table->decimal('panic', 5, 2)->default(50)->comment('恐慌指標 0-100');
            $table->decimal('international', 5, 2)->default(50)->comment('國際風向 0-100');
            $table->integer('article_count')->default(0);
            $table->timestamps();

            $table->unique(['date', 'scope', 'scope_value']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_indices');
        Schema::dropIfExists('news_articles');
    }
};
