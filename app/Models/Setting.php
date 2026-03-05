<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $setting = static::where('key', $key)->first();
        } catch (\Illuminate\Database\QueryException) {
            static::ensureTableExists();
            $setting = static::where('key', $key)->first();
        }

        if (! $setting) {
            return $default;
        }

        $decoded = json_decode($setting->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value;
    }

    public static function set(string $key, mixed $value): void
    {
        $storedValue = is_array($value) ? json_encode($value) : $value;

        try {
            static::updateOrCreate(
                ['key' => $key],
                ['value' => $storedValue],
            );
        } catch (\Illuminate\Database\QueryException) {
            static::ensureTableExists();
            static::updateOrCreate(
                ['key' => $key],
                ['value' => $storedValue],
            );
        }
    }

    private static function ensureTableExists(): void
    {
        $schema = static::resolveConnection()->getSchemaBuilder();

        if (! $schema->hasTable('settings')) {
            $schema->create('settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }
}
