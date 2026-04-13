<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_monitors', function (Blueprint $table) {
            $table->text('skip_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_monitors', function (Blueprint $table) {
            $table->string('skip_reason')->nullable()->change();
        });
    }
};
