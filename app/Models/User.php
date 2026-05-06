<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'organization_id',
        'is_active',
        'avatar',              // ← was missing
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function examinations(): HasMany
    {
        return $this->hasMany(Examination::class, 'doctor_id');
    }


    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'doctor_id');
    }

    public function wsiUploads(): HasMany
    {
        return $this->hasMany(WsiUpload::class, 'uploaded_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
