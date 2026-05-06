<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'plan_id',
        'amount',
        'currency',
        'payment_method',
        'status',              // pending | completed | failed
        'transaction_id',
        'chargily_checkout_id',
        'checkout_url',
        'duration_months',
    ];

    protected $casts = [
        'amount'          => 'float',
        'duration_months' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
