<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('name', 120);
            $table->string('type', 16)->default('button'); // generic|button
            $table->json('definition'); // generic: {elements:[...]} · button: {text, buttons:[...]}
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_templates');
    }
};
