<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const DEFAULT_LIMIT = 99;

    protected $fillable = ['key', 'value'];

    public $timestamps = false;

    public static function getValue(string $key, $default = null)
    {
        return static::where('key', $key)->value('value') ?? $default;
    }
}
