<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AccountSetupInvitation extends Model
{
    protected $fillable = ['user_id', 'created_by', 'token_hash', 'expires_at', 'used_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function issue(User $user, User $actor): string
    {
        static::where('user_id', $user->id)->whereNull('used_at')->update(['expires_at' => now()]);
        $token = Str::random(64);
        static::create(['user_id' => $user->id, 'created_by' => $actor->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48)]);

        return route('account.setup', ['token' => $token]);
    }
}
