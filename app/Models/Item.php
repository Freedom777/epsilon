<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id',
        'raw_response',
        'title',
        'description',
        'type',
        'subtype',
        'grade',
        'rarity',
        'extra',
        'durability_max',
        'personal',
        'price',
        'status',
    ];

    protected $casts = [
        'id'       => 'integer',
        'personal' => 'boolean',
    ];
}
