<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table): void {
            $table->id();
            // Match the core tables' workspace_id shape (no FK on purpose — the
            // module must survive independently).
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('ig_user_id')->index(); // IG professional account id (webhook entry.id)
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('page_id')->nullable()->index();
            $table->text('page_token'); // Page access token used for ALL Graph calls
            $table->string('status', 32)->default('active'); // active|token_expired|disconnected
            $table->json('meta_json')->nullable(); // scopes, connected_at, token expiry
            $table->timestamps();

            $table->unique(['workspace_id', 'ig_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
