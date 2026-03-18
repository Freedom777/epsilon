<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Mob extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id', 'location_id', 'title', 'level',
        'exp', 'gold', 'status',
        'raw_response',
    ];

    protected $casts = [
        'id' => 'integer',
    ];

    // =========================================================================
    // Связи
    // =========================================================================

    public function locationRef(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

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
    // Аксессоры — совместимость с фронтом
    // =========================================================================

    /**
     * Город: из связи Location → City, fallback на строковую колонку.
     */
    protected function cityName(): Attribute
    {
        return Attribute::get(fn () =>
            $this->locationRef?->city?->title ?? $this->city
        );
    }

    /**
     * Локация: из связи Location, fallback на строковую колонку.
     */
    protected function locationName(): Attribute
    {
        return Attribute::get(fn () =>
            $this->locationRef?->title ?? $this->location
        );
    }

    /**
     * Дроп ресурсов — массив строк (title) для HTML.
     */
    protected function dropAsset(): Attribute
    {
        return Attribute::get(fn () =>
        $this->dropAssets->pluck('title')->toArray() ?: null
        );
    }

    /**
     * Дроп предметов — массив строк (title) для HTML.
     */
    protected function dropItem(): Attribute
    {
        return Attribute::get(fn () =>
        $this->dropItems->pluck('title')->toArray() ?: null
        );
    }
}
