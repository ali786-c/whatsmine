<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen ai_provider_configs.provider to cover every provider the code supports.
 *
 * The original ENUM only had openai/anthropic/gemini — saving an 'omniroute'
 * or 'ollama' row either threw (strict mode) or silently truncated to ''
 * (non-strict), so workspace-level OmniRoute/Ollama configs never persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->enum('provider', ['openai', 'anthropic', 'gemini', 'omniroute', 'ollama'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->enum('provider', ['openai', 'anthropic', 'gemini'])->change();
        });
    }
};
