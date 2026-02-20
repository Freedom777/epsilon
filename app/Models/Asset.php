<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id',
        'raw_response',
        'title',
        'normalized_title',
        'type',
        'subtype',
        'description',
        'drop_monster',
        'status',
    ];

    protected $casts = [
        'id' => 'integer',
    ];
}
