<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssuePasswordRecovery extends Command
{
    protected $signature = 'barihafi:issue-recovery {phone}';

    protected $description = 'Issue a one-hour single-use recovery link after independently verifying identity';

    public function handle(): int
    {
        try {
            $phone = Phone::normalize($this->argument('phone'));
        } catch (ValidationException $e) {
            $this->error('Invalid phone number.');

            return self::FAILURE;
        }
        $user = User::where('phone', $phone)->first();
        if (! $user || ! $this->confirm('Have you independently verified this account holder and arranged secure private delivery?')) {
            $this->error('Recovery link not issued.');

            return self::FAILURE;
        }
        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $phone], ['token' => Hash::make($token), 'created_at' => now()]);
        $this->info(route('password.reset', ['token' => $token, 'phone' => $phone]));
        $this->warn('Deliver privately to the verified holder. Do not log or share this link.');

        return self::SUCCESS;
    }
}
