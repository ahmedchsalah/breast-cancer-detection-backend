<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlContribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'fl_round_id',
        'organization_id',
        'local_sample_size',
        'local_accuracy_before',
        'local_accuracy_after',
        'weights_update_path',
        'weights_hash',
        'aggregation_method',
    ];

    protected $casts = [
        'local_accuracy_before' => 'float',
        'local_accuracy_after'  => 'float',
    ];

    public function flRound(): BelongsTo
    {
        return $this->belongsTo(FlRound::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
