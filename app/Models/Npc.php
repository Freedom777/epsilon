<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Npc extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id', 'city_id', 'title', 'normalized_title',
    ];

    protected $casts = [
        'id' => 'integer',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
