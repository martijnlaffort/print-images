<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posters', function (Blueprint $table) {
            $table->timestamp('pushed_at')->nullable()->after('file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('posters', function (Blueprint $table) {
            $table->dropColumn('pushed_at');
        });
    }
};
