<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_flow_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('flow_id')->constrained('instagram_flows')->cascadeOnDelete();
            $table->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $table->string('commenter_igsid')->index(); // per-account scoped IGSID
            $table->string('username')->nullable();
            $table->string('comment_id')->nullable()->index(); // comment that started the flow
            $table->string('media_id')->nullable();
            $table->string('current_node_id')->nullable();
            $table->string('waiting_for')->nullable(); // null | 'reply'
            $table->json('context')->nullable(); // { last_inbound_text, vars... }
            $table->timestamp('wait_started_at')->nullable();
            $table->timestamp('dm_thread_opened_at')->nullable(); // 24h window start
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('status', 16)->default('active')->index(); // active | completed | expired
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_flow_participants');
    }
};
