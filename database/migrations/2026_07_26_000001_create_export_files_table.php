<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poster_id')->constrained()->cascadeOnDelete();
            $table->string('size');
            $table->string('path');
            $table->string('status'); // released | review | blocked
            $table->unsignedTinyInteger('printqc_exit')->nullable();
            $table->string('printqc_status')->nullable(); // PASS | REVIEW | FAIL
            $table->json('findings')->nullable();
            $table->json('meta')->nullable();
            $table->string('json_path')->nullable();
            $table->string('report_dir')->nullable();
            $table->string('md5', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_files');
    }
};
