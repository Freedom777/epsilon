<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'id',
        'city_id',
        'title',
        'normalized_title',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function mobs(): HasMany
    {
        return $this->hasMany(Mob::class);
    }
}
