<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->integer('history_limit')->default(5)->after('max_context_chunks');
            $table->integer('max_tokens')->default(256)->after('history_limit');
            $table->integer('num_ctx')->default(2048)->after('max_tokens');
            $table->string('keep_alive', 20)->default('10m')->after('num_ctx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->dropColumn(['history_limit', 'max_tokens', 'num_ctx', 'keep_alive']);
        });
    }
};
