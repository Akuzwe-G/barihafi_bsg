<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SystemAdminSeeder extends Seeder
{
    public function run(): void
    {
        $data = config('barihafi.initial_admin', []);
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72', Password::min(16)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails() || strlen($data['password']) > 72 || str_contains($data['password'], "\0")) {
            throw new RuntimeException('Initial administrator configuration is missing or invalid. Use a 16-72 character password with uppercase, lowercase, numbers and symbols.');
        }
        try {
            $data['phone'] = Phone::normalize($data['phone']);
        } catch (ValidationException) {
            throw new RuntimeException('Initial administrator configuration requires a valid international phone number.');
        }
        $data['email'] = filled($data['email'] ?? null) ? strtolower(trim($data['email'])) : null;
        try {
            DB::transaction(function () use ($data): void {
                $matches = User::query()->where('phone', $data['phone'])
                    ->when($data['email'] !== null, fn ($query) => $query->orWhere('email', $data['email']))
                    ->lockForUpdate()->get();
                if ($matches->isNotEmpty()) {
                    $existing = $matches->first();
                    if ($matches->count() === 1 && $existing->is_system_admin
                        && $existing->phone === $data['phone'] && $existing->email === $data['email']) {
                        return;
                    }
                    throw new RuntimeException('Initial administrator setup conflicts with an existing account. No account was modified.');
                }
                if (User::where('is_system_admin', true)->lockForUpdate()->exists()) {
                    throw new RuntimeException('An initial platform administrator already exists. No account was modified.');
                }
                $user = new User([
                    'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);
                $user->is_system_admin = true;
                $user->must_change_password = true;
                $user->save();
            });
        } catch (QueryException) {
            throw new RuntimeException('Initial administrator setup could not be completed. Check database readiness and account conflicts.');
        }
    }
}
