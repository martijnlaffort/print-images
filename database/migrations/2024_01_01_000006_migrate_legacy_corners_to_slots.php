<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate any templates that have corners data but no template_slots rows
        $templates = DB::table('mockup_templates')
            ->whereNotNull('corners')
            ->get();

        foreach ($templates as $template) {
            $hasSlots = DB::table('template_slots')
                ->where('template_id', $template->id)
                ->exists();

            if (! $hasSlots) {
                DB::table('template_slots')->insert([
                    'template_id' => $template->id,
                    'label' => 'Main',
                    'corners' => $template->corners,
                    'aspect_ratio' => $template->aspect_ratio ?? 'portrait',
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Make corners nullable now that all data lives in template_slots
        Schema::table('mockup_templates', function (Blueprint $table) {
            $table->json('corners')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mockup_templates', function (Blueprint $table) {
            $table->json('corners')->nullable(false)->change();
        });
    }
};
