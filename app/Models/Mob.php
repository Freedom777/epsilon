<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    // =========================================================================
    // Связи
    // =========================================================================

    public function assetDrops(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'mob_asset_drops')
            ->withTimestamps();
    }

    public function itemDrops(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'mob_item_drops')
            ->withTimestamps();
    }

// =========================================================================
// Аксессоры — совместимость с фронтом (возвращают массив строк)
// =========================================================================

    protected function dropAsset(): Attribute
    {
        return Attribute::get(fn () =>
        $this->assetDrops->pluck('title')->toArray() ?: null
        );
    }

    protected function dropItem(): Attribute
    {
        return Attribute::get(fn () =>
        $this->itemDrops->pluck('title')->toArray() ?: null
        );
    }
}
