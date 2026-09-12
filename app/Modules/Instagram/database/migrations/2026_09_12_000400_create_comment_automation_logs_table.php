<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_automation_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('instagram_account_id')->nullable()->constrained('instagram_accounts')->nullOnDelete();
            $table->foreignId('automation_id')->nullable()->constrained('comment_automations')->nullOnDelete();
            $table->foreignId('funnel_participant_id')->nullable()->constrained('funnel_participants')->nullOnDelete();
            $table->string('comment_id')->nullable()->index();
            $table->string('action', 48)->index();
            // received|ignored_own_comment|ignored_reply|no_match|matched|matched_inactive|
            // dm_sent|dm_failed|awaiting_follow|nudged|delivered|delivery_failed|
            // expired|closed|rate_limited|skipped
            $table->string('stage', 32)->nullable();
            $table->json('request_json')->nullable();
            $table->json('response_json')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('comment_automation_logs', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_automation_logs');
    }
};
