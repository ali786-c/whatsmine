<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Interactive-choice branching: when a run parks on quick_replies /
        // list_message / send_poll with wait_for_choice, the tapped button or
        // row id is stored here so the resumed run takes the matching
        // sourceHandle edge (per-option branch) instead of the plain one.
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->string('resume_edge_handle', 64)->nullable()->after('resume_node_id');
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropColumn('resume_edge_handle');
        });
    }
};
