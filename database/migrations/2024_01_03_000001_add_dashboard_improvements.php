<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posters', function (Blueprint $table) {
            $table->string('file_hash')->nullable()->index()->after('metadata');
            $table->softDeletes();
        });

        Schema::create('poster_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poster_id')->constrained()->cascadeOnDelete();
            $table->string('action')->index();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poster_activities');

        Schema::table('posters', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['file_hash']);
            $table->dropColumn('file_hash');
        });
    }
};
