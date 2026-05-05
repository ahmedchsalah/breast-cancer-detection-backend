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
        'price',
        'max_users',
        'max_storage_gb',
    ];

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
