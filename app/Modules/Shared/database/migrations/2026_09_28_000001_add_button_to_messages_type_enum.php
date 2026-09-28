<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template quick replies arrive from Meta as message type "button" (with the
 * developer payload id) — without this value in the enum every template
 * quick-reply reply is stored as "unsupported".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', [
                'text', 'template', 'media', 'interactive', 'reaction',
                'image', 'video', 'document', 'audio', 'location', 'contacts',
                'sticker', 'order', 'poll', 'event', 'button', 'unsupported',
            ])->default('text')->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', [
                'text', 'template', 'media', 'interactive', 'reaction',
                'image', 'video', 'document', 'audio', 'location', 'contacts',
                'sticker', 'order', 'poll', 'event', 'unsupported',
            ])->default('text')->change();
        });
    }
};
