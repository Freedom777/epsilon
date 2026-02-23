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
        'drop_asset', 'drop_item', 'extra', 'status',
    ];

    protected $casts = [
        'id'            => 'integer',
        'drop_asset'    => 'array',
        'drop_item'     => 'array',
    ];
}
