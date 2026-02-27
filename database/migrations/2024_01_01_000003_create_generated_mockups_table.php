<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_mockups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('mockup_templates')->cascadeOnDelete();
            $table->string('output_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_mockups');
    }
};
