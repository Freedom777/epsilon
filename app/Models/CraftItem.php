<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CraftItem extends Model
{
    protected $fillable = [
        'item_id',
        'city',
        'crafter',
    ];

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
