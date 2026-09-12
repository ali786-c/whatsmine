<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_automations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $table->string('name');
            $table->string('trigger_type', 32)->default('keyword'); // all_comments|keyword|mention_only
            $table->json('keywords')->nullable(); // ["price","cost"]
            $table->string('match_mode', 16)->default('contains'); // contains|exact|starts_with|regex
            $table->text('reply_message');
            $table->boolean('follow_gate')->default(false);
            $table->text('follow_prompt_message')->nullable();
            $table->string('reply_keyword', 64)->nullable(); // e.g. DONE — completes the follow gate
            $table->json('delivery')->nullable(); // {type: text|link|file, text, url, filename}
            $table->json('media_filter')->nullable(); // feed/reel/story/ad product types
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_automations');
    }
};
