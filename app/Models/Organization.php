<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
class Organization extends Model
{
    use HasFactory;

    public const TYPE_CLINIC           = 'clinic';
    public const TYPE_HOSPITAL         = 'hospital';
    public const TYPE_LABORATORY       = 'laboratory';
    public const TYPE_RADIOLOGY        = 'radiology_center';

    public const STATUS_PENDING        = 'pending';
    public const STATUS_ACTIVE         = 'active';
    public const STATUS_REJECTED       = 'rejected';
    public const STATUS_SUSPENDED      = 'suspended';

    protected $fillable = [
        'plan_id',
        'name',
        'type',
        'status',
        'address',
        'latitude',            // ← was missing
        'longitude',           // ← was missing
        'contact_email',
        'subscription_status',
        'subscription_ends_at',
    ];

    protected $casts = [
        'subscription_ends_at' => 'date',
        'latitude'             => 'float', // ← cast
        'longitude'            => 'float', // ← cast
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

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function examinations(): HasMany
    {
        return $this->hasMany(Examination::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);  // ← removed doctor_id, org scoped
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function flContributions(): HasMany
    {
        return $this->hasMany(FlContribution::class);
    }

    public function flRoundInvitations(): HasMany
    {
        return $this->hasManyThrough(FlRoundInvitation::class, User::class, 'organization_id', 'instructor_id');
    }

    public function wsiUploads(): HasMany
    {
        return $this->hasMany(WsiUpload::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
