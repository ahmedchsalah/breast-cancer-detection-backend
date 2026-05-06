<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Otp extends Model
{
    use HasFactory;

    protected $fillable = [
        'identifier',
        'token',
        'method',      // ← was missing from fillable
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime', // ← add cast
    ];
}
