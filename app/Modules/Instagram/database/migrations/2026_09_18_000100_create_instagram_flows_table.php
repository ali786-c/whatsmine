<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_flows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->foreignId('instagram_account_id')->nullable()->constrained('instagram_accounts')->nullOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('draft')->index(); // draft | active
            $table->json('graph'); // { nodes: [...], edges: [...] }
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_flows');
    }
};
