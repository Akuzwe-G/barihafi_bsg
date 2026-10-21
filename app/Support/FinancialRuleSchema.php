<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialRuleSchema
{
    /** @return array<string, array{section: string, label: string, default: mixed, validation: array, options: array}> */
    public static function fields(): array
    {
        $money = ['nullable', 'regex:/^[0-9]{1,10}(\.[0-9]{1,2})?$/'];
        $positive = ['required', 'regex:/^(?!0+(\.0+)?$)[0-9]{1,10}(\.[0-9]{1,2})?$/'];
        $decimal = ['required', 'regex:/^[0-9]{1,3}(\.[0-9]{1,4})?$/'];
        $fields = [];
        $add = function (string $section, string $key, string $label, mixed $default, array $validation, array $options = []) use (&$fields): void {
            $fields[$key] = compact('section', 'label', 'default', 'validation', 'options');
        };
        $add('shares', 'currency', 'Club currency', 'RWF', ['required', 'regex:/^[A-Z]{3}$/']);
        $add('shares', 'share_price', 'Share price', null, $positive);
        $add('shares', 'minimum_membership_shares', 'Minimum completed membership shares', 1, ['required', 'integer', 'min:1', 'max:1000000']);
        $add('shares', 'partial_shares_allowed', 'Allow partial contributions', true, ['required', 'boolean'], [0 => 'No', 1 => 'Yes']);
        $add('shares', 'partial_capital_loan_eligible', 'Verified partial capital counts for loan eligibility', true, ['required', 'boolean'], [0 => 'No', 1 => 'Yes']);
        $add('shares', 'activation_threshold', 'Completed shares required for activation', 1, ['required', 'integer', 'min:1', 'max:1000000']);
        $add('shares', 'share_precision', 'Contribution decimal places', 2, ['required', 'integer', 'min:0', 'max:2']);
        $add('shares', 'share_exit_policy', 'Share capital withdrawal / exit rules', 'Approval required on exit; subject to outstanding obligations.', ['required', 'string', 'max:2000']);
        $add('savings', 'savings_supported', 'Ordinary savings supported', true, ['required', 'boolean'], [0 => 'No', 1 => 'Yes']);
        $add('savings', 'minimum_savings_deposit', 'Minimum savings deposit (optional)', null, $money);
        $add('savings', 'savings_frequency', 'Expected savings frequency', 'none', ['required', Rule::in(['none', 'weekly', 'monthly', 'custom'])], array_combine(['none', 'weekly', 'monthly', 'custom'], ['None', 'Weekly', 'Monthly', 'Custom']));
        $add('savings', 'savings_custom_frequency', 'Custom frequency description', null, ['nullable', 'string', 'max:500']);
        $add('savings', 'savings_withdrawal_restrictions', 'Withdrawal eligibility restrictions', 'Independent approval required; outstanding obligations must be considered.', ['required', 'string', 'max:2000']);
        $add('savings', 'minimum_retained_savings', 'Minimum retained savings (optional)', null, $money);
        $add('savings', 'savings_withdrawal_notice_days', 'Withdrawal notice (days)', 30, ['required', 'integer', 'min:0', 'max:3650']);
        $add('savings', 'savings_withdrawal_approval_required', 'Withdrawal approval required', true, ['required', 'accepted'], [1 => 'Required']);
        $add('loans', 'loan_multiplier', 'Loan multiplier', '2.00', ['required', 'numeric', 'min:0.01', 'max:100', 'regex:/^[0-9]{1,3}(\.[0-9]{1,4})?$/']);
        $add('loans', 'minimum_loan_amount', 'Minimum loan amount (optional)', null, $money);
        $add('loans', 'maximum_loan_amount', 'Maximum loan amount (optional)', null, $money);
        $add('loans', 'interest_rate', 'Interest rate (%)', '0.00', $decimal);
        $add('loans', 'interest_period', 'Interest rate period', 'per_term', ['required', Rule::in(['monthly', 'annual', 'per_term'])], ['monthly' => 'Monthly', 'annual' => 'Annual', 'per_term' => 'Per loan term']);
        $add('loans', 'interest_method', 'Interest calculation', 'flat', ['required', Rule::in(['flat', 'reducing_balance'])], ['flat' => 'Flat', 'reducing_balance' => 'Reducing balance']);
        $add('loans', 'repayment_options', 'Permitted repayments', 'both', ['required', Rule::in(['instalments', 'lump_sum', 'both'])], ['instalments' => 'Instalments', 'lump_sum' => 'Lump sum', 'both' => 'Both']);
        $add('loans', 'minimum_duration_months', 'Minimum duration (months)', 1, ['required', 'integer', 'min:1', 'max:120']);
        $add('loans', 'loan_duration_months', 'Maximum duration (months)', 12, ['required', 'integer', 'min:1', 'max:120']);
        $add('loans', 'repayment_frequency', 'Instalment frequency', 'monthly', ['required', Rule::in(['weekly', 'monthly', 'quarterly'])], ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly']);
        $add('loans', 'early_repayment_allowed', 'Early repayment allowed', true, ['required', 'boolean'], [0 => 'No', 1 => 'Yes']);
        $add('loans', 'late_repayment_policy', 'Late repayment policy', 'Independent review required; no automatic penalties configured.', ['required', 'string', 'max:2000']);
        $add('loans', 'minimum_cash_reserve', 'Minimum cash reserve', '0.00', ['required', 'regex:/^[0-9]{1,10}(\.[0-9]{1,2})?$/']);
        $add('loans', 'maximum_lendable_percent', 'Maximum available funds lendable (%)', '80.00', ['required', 'numeric', 'min:0', 'max:100', 'regex:/^[0-9]{1,3}(\.[0-9]{1,2})?$/']);
        $add('governance', 'committee_minimum_members', 'Minimum eligible committee members', 3, ['required', 'integer', 'min:3', 'max:100']);
        $add('governance', 'committee_quorum', 'Independent committee approvals required', 2, ['required', 'integer', 'min:2', 'max:100']);

        return $fields;
    }

    /** @return array<string, mixed> */
    public static function snapshot(array $changes, array $existing = []): array
    {
        $fields = self::fields();
        Validator::make(['rules' => $changes], ['rules' => ['required', 'array:'.implode(',', array_keys($fields))]])->validate();
        $rules = array_replace(array_map(fn (array $field) => $field['default'], $fields), $existing, $changes);
        if (! array_key_exists('activation_threshold', $changes) && ! array_key_exists('activation_threshold', $existing)) {
            $rules['activation_threshold'] = $rules['minimum_membership_shares'];
        }
        $validation = [];
        foreach ($fields as $key => $field) {
            $validation[$key] = $field['validation'];
        }
        $rules = Validator::make($rules, $validation)->validate();
        if ($rules['activation_threshold'] < $rules['minimum_membership_shares'] || $rules['minimum_duration_months'] > $rules['loan_duration_months'] || $rules['committee_quorum'] > $rules['committee_minimum_members']) {
            throw ValidationException::withMessages(['rules' => 'Activation, duration or committee quorum limits are inconsistent.']);
        }
        if ($rules['savings_frequency'] === 'custom' && empty($rules['savings_custom_frequency'])) {
            throw ValidationException::withMessages(['savings_custom_frequency' => 'Describe the custom savings frequency.']);
        }
        if ($rules['minimum_loan_amount'] !== null && $rules['maximum_loan_amount'] !== null && self::cents($rules['minimum_loan_amount']) > self::cents($rules['maximum_loan_amount'])) {
            throw ValidationException::withMessages(['maximum_loan_amount' => 'Maximum loan must be at least the minimum.']);
        }
        foreach ($fields as $key => $field) {
            if (in_array('integer', $field['validation'], true)) {
                $rules[$key] = (int) $rules[$key];
            } elseif (in_array('boolean', $field['validation'], true) || in_array('accepted', $field['validation'], true)) {
                $rules[$key] = (bool) $rules[$key];
            } elseif ($rules[$key] !== null) {
                $rules[$key] = (string) $rules[$key];
            }
        }

        return $rules;
    }

    private static function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function display(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : ($value === null ? 'Not configured' : (string) $value);
    }
}
