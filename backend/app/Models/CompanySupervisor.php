<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanySupervisor extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'position',
        'phone',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(PklPlacement::class, 'supervisor_id');
    }
}