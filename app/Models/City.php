<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = [
        'id',
        'title',
        'normalized_title',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function npcs(): HasMany
    {
        return $this->hasMany(Npc::class);
    }
}
