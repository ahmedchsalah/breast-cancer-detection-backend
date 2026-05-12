<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'max_doctors',
        'max_predictions_per_month',
        'fl_contribution_allowed',
        'instructor_allowed',
        'is_active',
    ];

    protected $casts = [
        'price'                    => 'decimal:2',
        'max_doctors'              => 'integer',
        'max_predictions_per_month'=> 'integer',
        'fl_contribution_allowed'  => 'boolean',
        'instructor_allowed'       => 'boolean',
        'is_active'                => 'boolean',
    ];

    /** -1 means unlimited */
    public function isUnlimitedDoctors(): bool
    {
        return $this->max_doctors === -1;
    }

    public function isUnlimitedPredictions(): bool
    {
        return $this->max_predictions_per_month === -1;
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
