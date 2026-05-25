<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FlRoundInvitation extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'fl_round_id',
        'instructor_id',
        'token',
        'status',
        'responded_at',
        'submitted_at',
        'local_accuracy',
        'local_loss',
        'weights_hash',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'submitted_at' => 'datetime',
        'local_accuracy' => 'float',
        'local_loss' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $inv) {
            if (empty($inv->token)) {
                $inv->token = Str::random(64);
            }
        });
    }

    public function flRound(): BelongsTo
    {
        return $this->belongsTo(FlRound::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'instructor_id');
    }
}
