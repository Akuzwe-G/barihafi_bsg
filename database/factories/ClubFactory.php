<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ClubFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'is_active' => true, 'join_code' => Str::random(40)];
    }
}
