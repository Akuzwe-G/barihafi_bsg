<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinancialRuleProposalFactory extends Factory
{
    public function definition(): array
    {
        return ['club_id' => Club::factory(), 'proposed_by' => User::factory(), 'rules' => ['share_price' => '1000.00'], 'base_version' => 0, 'rationale' => 'Proposed club policy for review.', 'status' => 'pending'];
    }
}
