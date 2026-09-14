<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram/WhatsApp CDN profile-picture URLs regularly exceed 500 characters
 * (signature + tracking query params), overflowing the previous varchar(512)
 * `contacts.avatar` column. The resulting SQLSTATE[22001] aborted the whole
 * inbound-message transaction, so DMs silently never reached the Inbox.
 * Store the URL as TEXT so no external avatar URL can ever truncate again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('contacts', 'avatar') && Schema::getColumnType('contacts', 'avatar') !== 'text') {
            Schema::table('contacts', function (Blueprint $table) {
                $table->text('avatar')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contacts', 'avatar')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->string('avatar', 512)->nullable()->change();
            });
        }
    }
};
