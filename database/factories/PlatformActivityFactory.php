<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformActivityFactory extends Factory
{
    public function definition(): array
    {
        return ['actor_id' => User::factory(), 'action' => 'platform.reviewed', 'subject_type' => 'platform', 'subject_id' => 1, 'changes' => []];
    }
}
