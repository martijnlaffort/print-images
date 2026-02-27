<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mockup_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('category');
            $table->string('background_path');
            $table->string('shadow_path')->nullable();
            $table->string('frame_path')->nullable();
            $table->json('corners');
            $table->integer('brightness_adjust')->default(100);
            $table->string('aspect_ratio')->default('portrait');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mockup_templates');
    }
};
