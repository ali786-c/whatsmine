<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rolling per-conversation summary — gives the bot memory of turns that
        // fell outside the replayed history window, WITHOUT changing history
        // settings (the existing history_limit replay is untouched).
        Schema::create('ai_conversation_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('workspace_id')->index();
            $table->text('summary');
            $table->unsignedBigInteger('last_summarized_message_id')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();

            $table->unique('conversation_id');
            $table->index(['workspace_id', 'updated_at'], 'ai_conv_summaries_ws_updated_idx');
        });

        // Workspace-level learned facts: pricing promises, policies, customer
        // preferences, business info. Extracted in the background from real
        // conversations; injected as a compact block into every AI reply.
        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('chatbot_id')->nullable()->index();
            $table->enum('kind', ['fact', 'preference', 'policy', 'order_info', 'business']);
            $table->string('content', 500);
            $table->unsignedInteger('usefulness')->default(1);
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('source_conversation_id')->nullable();
            $table->enum('source', ['auto', 'manual'])->default('auto');
            $table->timestamps();

            $table->index(['workspace_id', 'usefulness'], 'ai_memories_ws_useful_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
        Schema::dropIfExists('ai_conversation_summaries');
    }
};
