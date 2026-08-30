<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_qr_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->unique();
            $table->string('title', 100)->default('WhatsApp');
            $table->string('phone_number', 20)->nullable();
            $table->string('whatsapp_jid', 50)->nullable();
            $table->enum('status', ['generating', 'waiting_scan', 'active', 'disconnected', 'logged_out'])->default('generating');
            $table->text('qr_code')->nullable();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->index('workspace_id', 'idx_qr_workspace_status');
            $table->index(['workspace_id', 'status']);
        });

        // Add type column to channel_accounts if it doesn't exist
        if (! Schema::hasColumn('channel_accounts', 'type')) {
            Schema::table('channel_accounts', function (Blueprint $table) {
                $table->string('type', 20)->default('cloud_api')->after('provider');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_qr_sessions');

        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
