<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Alter ENUM column to add 'ollama'
        DB::statement("ALTER TABLE ai_provider_configs MODIFY COLUMN provider ENUM('openai', 'anthropic', 'gemini', 'ollama') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back (Note: Data with 'ollama' should be handled manually if reverted)
        DB::statement("ALTER TABLE ai_provider_configs MODIFY COLUMN provider ENUM('openai', 'anthropic', 'gemini') NOT NULL");
    }
};
