<?php

namespace Database\Factories;

use App\Models\Club;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClubPaymentDestinationFactory extends Factory
{
    public function definition(): array
    {
        return ['club_id' => Club::factory(), 'type' => 'bank', 'label' => 'Club bank account', 'bank_name' => fake()->company(), 'account_name' => fake()->company(), 'account_number' => fake()->numerify('##########'), 'is_active' => true];
    }
}
