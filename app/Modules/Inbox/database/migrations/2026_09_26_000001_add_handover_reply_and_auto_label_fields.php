<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI handover auto-label system:
 *
 * - ai_chatbots.handover_reply: the configurable "connecting you with a
 *   human" message the bot sends when handover fires (empty = platform
 *   default, language-aware).
 * - inbox_labels.auto_assigned: marks the workspace's system-generated
 *   "Waiting for you" label so it is auto-created once and never offered as
 *   a normal editable clutter item twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->string('handover_reply', 512)->nullable()->after('fallback_reply');
        });

        Schema::table('inbox_labels', function (Blueprint $table) {
            $table->boolean('auto_assigned')->default(false)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbots', function (Blueprint $table) {
            $table->dropColumn('handover_reply');
        });

        Schema::table('inbox_labels', function (Blueprint $table) {
            $table->dropColumn('auto_assigned');
        });
    }
};
