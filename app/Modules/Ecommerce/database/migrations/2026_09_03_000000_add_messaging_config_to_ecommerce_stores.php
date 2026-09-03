<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ecommerce_stores', function (Blueprint $table) {
            $table->json('messaging_config')->nullable()->after('external_meta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ecommerce_stores', function (Blueprint $table) {
            $table->dropColumn('messaging_config');
        });
    }
};
