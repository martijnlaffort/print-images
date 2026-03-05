<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureNativephpMigrations();
    }

    private function ensureNativephpMigrations(): void
    {
        try {
            $connection = DB::connection();
            $schemaBuilder = $connection->getSchemaBuilder();

            $requiredTables = ['posters', 'mockup_templates', 'generated_mockups', 'settings', 'template_slots'];
            $missing = false;

            foreach ($requiredTables as $table) {
                if (! $schemaBuilder->hasTable($table)) {
                    $missing = true;
                    break;
                }
            }

            if ($missing) {
                $database = $connection->getDatabaseName();
                $pdo = $connection->getPdo();

                // Run each migration table creation directly via PDO
                if (! $schemaBuilder->hasTable('settings')) {
                    $pdo->exec('CREATE TABLE IF NOT EXISTS "settings" (
                        "id" integer primary key autoincrement not null,
                        "key" varchar not null,
                        "value" text,
                        "created_at" datetime,
                        "updated_at" datetime
                    )');
                    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS "settings_key_unique" ON "settings" ("key")');
                }

                if (! $schemaBuilder->hasTable('template_slots')) {
                    $pdo->exec('CREATE TABLE IF NOT EXISTS "template_slots" (
                        "id" integer primary key autoincrement not null,
                        "template_id" integer not null,
                        "label" varchar not null default \'Main\',
                        "corners" text not null,
                        "aspect_ratio" varchar not null default \'portrait\',
                        "sort_order" integer not null default 0,
                        "created_at" datetime,
                        "updated_at" datetime,
                        foreign key("template_id") references "mockup_templates"("id") on delete cascade
                    )');
                }
            }
        } catch (\Throwable) {
            // Silently ignore — connection may not be ready
        }
    }
}
