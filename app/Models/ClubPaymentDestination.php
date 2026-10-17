<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubPaymentDestination extends Model
{
    use HasFactory;

    protected $fillable = ['club_id', 'type', 'label', 'bank_name', 'account_name', 'account_number', 'provider', 'mobile_number', 'account_holder', 'is_active', 'created_by', 'updated_by'];

    protected $hidden = ['account_number', 'mobile_number'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'account_number' => 'encrypted', 'mobile_number' => 'encrypted'];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
