<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Phone
{
    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim($phone));
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter an international phone number including + and country code.']);
        }

        return $phone;
    }
}
