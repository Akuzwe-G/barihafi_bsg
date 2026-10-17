<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClubJoinRequest extends Model
{
    protected $fillable = ['club_id', 'user_id', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function history(): HasMany
    {
        return $this->hasMany(ClubJoinRequestEvent::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
