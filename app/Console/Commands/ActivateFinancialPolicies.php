<?php

namespace App\Console\Commands;

use App\Support\FinancialPolicyWorkflow;
use Illuminate\Console\Command;

class ActivateFinancialPolicies extends Command
{
    protected $signature = 'barihafi:activate-policies';

    protected $description = 'Activate due independently approved policies for available clubs';

    public function handle(FinancialPolicyWorkflow $workflow): int
    {
        $count = $workflow->activateDue();
        $this->info($count.' policy version(s) activated.');

        return self::SUCCESS;
    }
}
