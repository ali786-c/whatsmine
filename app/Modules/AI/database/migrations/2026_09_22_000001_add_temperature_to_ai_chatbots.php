<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->decimal('temperature', 3, 2)->default(0.30)->after('max_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->dropColumn('temperature');
        });
    }
};
