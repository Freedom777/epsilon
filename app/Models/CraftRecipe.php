<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CraftRecipe extends Model
{
    protected $fillable = [
        'item_id',
        'asset_id',
        'npc_id',
        'craft_level',
        'energy_cost',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function npc(): BelongsTo
    {
        return $this->belongsTo(Npc::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(CraftRecipeItemComponent::class);
    }

    /**
     * Результат крафта: item или asset.
     */
    public function product(): Item|Asset|null
    {
        return $this->item ?? $this->asset;
    }

    public function isItem(): bool
    {
        return $this->item_id !== null;
    }

    public function isAsset(): bool
    {
        return $this->asset_id !== null;
    }
}
