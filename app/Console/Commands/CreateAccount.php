<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreateAccount extends Command
{
    protected $signature = 'barihafi:create-account {--system-admin : Grant platform administration}';

    protected $description = 'Create a controlled user account, optionally a platform administrator';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Full name'), 'phone' => $this->ask('International phone (+ country code)'), 'email' => $this->ask('Email (optional)'), 'password' => $this->secret('Password (at least 12 characters)'), 'password_confirmation' => $this->secret('Confirm password')];
        try {
            $data['phone'] = Phone::normalize((string) $data['phone']);
        } catch (ValidationException $e) {
            $this->error('Invalid international phone number.');

            return self::FAILURE;
        }
        $data['email'] = $data['email'] ? strtolower(trim($data['email'])) : null;
        $validator = Validator::make($data, ['name' => 'required|string|max:150', 'phone' => 'required|unique:users,phone', 'email' => 'nullable|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User($validator->validated());
        $user->is_system_admin = (bool) $this->option('system-admin');
        $user->must_change_password = $user->is_system_admin;
        $user->save();
        $this->info('Account created without club membership.');

        return self::SUCCESS;
    }
}
