<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Structured storage for WhatsApp Flow (and other form) responses that
        // arrive through the Cloud API webhook as interactive nfm_reply. One row
        // per submitted form response; raw webhook JSON is kept alongside a
        // decoded field map for querying and follow-up automations.
        Schema::create('wa_flow_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('flow_name', 256)->nullable();
            $table->string('flow_token', 256)->nullable();
            $table->json('responses');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at'], 'wa_flow_responses_ws_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_flow_responses');
    }
};
