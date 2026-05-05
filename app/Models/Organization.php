<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Organization extends Model
{
    use HasFactory;

    // Enum Constants
    public const TYPE_CLINIC = 'clinic';
    public const TYPE_HOSPITAL = 'hospital';
    public const TYPE_LABORATORY = 'laboratory';
    public const TYPE_RADIOLOGY = 'radiology_center';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'plan_id',
        'name',
        'type',
        'status', // Added this
        'address',
        'contact_email',
        'subscription_status',
        'subscription_ends_at',
    ];

    protected $casts = [
        'subscription_ends_at' => 'date',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
