<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Values that change by law (such as the yearly rent cap) live here so they
 * can be updated without an app release.
 */
class Setting extends Model
{
    public const RENT_CAP_PERCENT = 'rent_cap_percent';

    public const RENT_NOTICE_DAYS = 'rent_notice_days';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting.$key", function () use ($key, $default) {
            return static::query()->find($key)?->value ?? $default;
        });
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget("setting.$key");
    }
}
