<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformActivity extends Model
{
    use HasFactory;

    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'club_id', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public static function record(?User $actor, string $action, Model $subject, ?Club $club = null, array $changes = []): self
    {
        $allowed = ['fields', 'status', 'previous_status', 'roles', 'previous_roles', 'administrator_id', 'previous_administrator_ids', 'currency', 'brand_color', 'is_system_admin'];

        return static::create(['actor_id' => $actor?->id, 'action' => $action, 'subject_type' => match (true) {
            $subject instanceof Club => 'club',$subject instanceof User => 'user',default => 'platform'
        }, 'subject_id' => $subject->getKey(), 'club_id' => $club?->id, 'changes' => array_intersect_key($changes, array_flip($allowed))]);
    }
}
