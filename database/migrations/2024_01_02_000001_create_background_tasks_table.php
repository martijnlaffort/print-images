<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('name');
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('stage')->nullable();
            $table->unsignedInteger('total_items')->default(1);
            $table->unsignedInteger('completed_items')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_tasks');
    }
};
