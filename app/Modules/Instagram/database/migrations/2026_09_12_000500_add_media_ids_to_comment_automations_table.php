<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional per-post scoping: when non-empty, the automation only fires on
        // comments whose media.id is in this list. Empty/null = account-wide.
        Schema::table('comment_automations', function (Blueprint $table): void {
            $table->json('media_ids')->nullable()->after('media_filter');
        });
    }

    public function down(): void
    {
        Schema::table('comment_automations', function (Blueprint $table): void {
            $table->dropColumn('media_ids');
        });
    }
};
