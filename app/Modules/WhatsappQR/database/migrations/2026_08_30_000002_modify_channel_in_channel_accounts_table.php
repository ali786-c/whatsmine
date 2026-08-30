<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Alter enum column to varchar to allow 'whatsapp_qr' and any future channel types
        DB::statement("ALTER TABLE channel_accounts MODIFY COLUMN channel VARCHAR(32) NOT NULL");
    }

    public function down(): void
    {
        // Revert to enum
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->enum('channel', ['whatsapp', 'instagram', 'messenger', 'sms', 'email'])->change();
        });
    }
};
