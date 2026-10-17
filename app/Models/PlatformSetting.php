<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['platform_name', 'currency', 'brand_color', 'brand_initials'];

    protected $attributes = ['platform_name' => 'Barihafi', 'currency' => 'RWF', 'brand_color' => '#E98A2A', 'brand_initials' => 'B'];

    public static function current(): self
    {
        return static::find(1) ?? new static;
    }
}
