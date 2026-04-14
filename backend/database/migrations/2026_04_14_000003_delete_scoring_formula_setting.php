<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        \App\Models\FormulaSetting::where('type', 'scoring')->delete();
    }

    public function down(): void
    {
        // scoring config 已移除，不提供 rollback
    }
};
