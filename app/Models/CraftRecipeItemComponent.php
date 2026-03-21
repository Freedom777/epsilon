<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CraftRecipeItemComponent extends Model
{
    protected $fillable = [
        'craft_recipe_id',
        'asset_id',
        'quantity',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(CraftRecipe::class, 'craft_recipe_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
