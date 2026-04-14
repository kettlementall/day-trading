<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->boolean('haiku_selected')->nullable()->after('score');
            $table->text('haiku_reasoning')->nullable()->after('haiku_selected');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['haiku_selected', 'haiku_reasoning']);
        });
    }
};
