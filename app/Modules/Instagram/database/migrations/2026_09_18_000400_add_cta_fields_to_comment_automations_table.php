<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two-step button funnel (competitor-style): the first private reply is
        // a hook text + one CTA button; the tap opens the follow gate with a
        // button template (Visit profile + "I'm following"). All user-editable.
        Schema::table('comment_automations', function (Blueprint $table) {
            $table->string('cta_message', 500)->nullable()->after('follow_prompt_message');
            $table->string('cta_button_label', 60)->nullable()->after('cta_message');
            $table->string('gate_message', 500)->nullable()->after('cta_button_label');
            $table->string('visit_profile_label', 60)->nullable()->after('gate_message');
            $table->string('confirm_follow_label', 60)->nullable()->after('visit_profile_label');
        });
    }

    public function down(): void
    {
        Schema::table('comment_automations', function (Blueprint $table) {
            $table->dropColumn([
                'cta_message',
                'cta_button_label',
                'gate_message',
                'visit_profile_label',
                'confirm_follow_label',
            ]);
        });
    }
};
