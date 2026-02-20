<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mob extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id', 'raw_response', 'title', 'level',
        'city', 'location', 'exp', 'gold',
        'drop', 'extra', 'status',
    ];

    protected $casts = [
        'id'   => 'integer',
        'drop' => 'array',
    ];
}
