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
        'extra', 'status',
    ];

    protected $casts = [
        'id'            => 'integer',
    ];

    // =========================================================================
    // Связи
    // =========================================================================

    public function dropAssets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'mob_drop_assets')
            ->withTimestamps();
    }

    public function dropItems(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'mob_drop_items')
            ->withTimestamps();
    }

// =========================================================================
// Аксессоры — совместимость с фронтом (возвращают массив строк)
// =========================================================================

    protected function dropAsset(): Attribute
    {
        return Attribute::get(fn () =>
        $this->dropAssets->pluck('title')->toArray() ?: null
        );
    }

    protected function dropItem(): Attribute
    {
        return Attribute::get(fn () =>
        $this->dropItems->pluck('title')->toArray() ?: null
        );
    }
}
