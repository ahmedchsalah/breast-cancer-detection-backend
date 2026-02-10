<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'name',
        'type',
        'code',
        'address',
        'contact_email',
        'subscription_status',
        'subscription_ends_at',
    ];

    protected $casts = [
        'subscription_ends_at' => 'date',
    ];

    // Relationships
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
