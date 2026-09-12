<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnel_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $table->foreignId('automation_id')->nullable()->constrained('comment_automations')->nullOnDelete();
            $table->string('commenter_igsid')->index(); // value.from.id — per-account scoped
            $table->string('username')->nullable();
            $table->string('comment_id')->unique(); // hard guarantee: ONE private reply per comment
            $table->string('media_id')->nullable();
            $table->string('media_product_type', 32)->nullable();
            $table->string('stage', 32)->default('commented')->index();
            // commented → dm_sent → awaiting_follow → replied → delivered / closed / expired
            $table->string('private_reply_message_id')->nullable();
            $table->timestamp('dm_thread_opened_at')->nullable(); // 24h window start
            $table->unsignedInteger('nudge_count')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('expires_at')->index(); // comment time + 7 days (private-reply window)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_participants');
    }
};
