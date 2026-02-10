<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Otp extends Model
{
    use HasFactory;

    protected $fillable = ['identifier', 'token', 'expires_at'];

    public $timestamps = true;
}
