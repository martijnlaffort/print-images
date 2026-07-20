<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poster_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_path');
            $table->string('phase')->default('source'); // source | denoised | output
            $table->string('verdict'); // pass | warn | fail
            $table->json('metrics');
            $table->json('reasons')->nullable();
            $table->json('comparison')->nullable(); // denoise before/after deltas
            $table->string('comparison_image_path')->nullable();
            $table->string('batch_id')->nullable()->index();
            $table->timestamps();

            $table->index(['poster_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_reports');
    }
};
