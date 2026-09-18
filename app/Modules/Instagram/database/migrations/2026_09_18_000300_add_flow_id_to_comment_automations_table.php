<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comment_automations', function (Blueprint $table): void {
            // Optional visual DM flow to run instead of the classic text/link/file
            // delivery — set when delivery.type === 'flow'.
            $table->foreignId('flow_id')->nullable()->after('delivery')->constrained('instagram_flows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comment_automations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('flow_id');
        });
    }
};
