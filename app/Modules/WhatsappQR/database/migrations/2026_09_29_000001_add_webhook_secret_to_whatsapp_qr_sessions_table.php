<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_qr_sessions', function (Blueprint $table) {
            $table->string('webhook_secret', 64)->nullable()->after('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_qr_sessions', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
