<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceReference extends Model
{
    protected $fillable = [
        'item_id',
        'asset_id',
        'buy_min',
        'buy_avg',
        'buy_max',
        'sell_min',
        'sell_avg',
        'sell_max',
        'sample_count',
        'period_days',
        'is_manual',
        'admin_note',
    ];

    protected $casts = [
        'buy_min'      => 'integer',
        'buy_avg'      => 'integer',
        'buy_max'      => 'integer',
        'sell_min'     => 'integer',
        'sell_avg'     => 'integer',
        'sell_max'     => 'integer',
        'sample_count' => 'integer',
        'period_days'  => 'integer',
        'is_manual'    => 'boolean',
    ];

    // =========================================================================
    // Связи
    // =========================================================================

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    // =========================================================================
    // Хелперы
    // =========================================================================

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

    public function productTitle(): string
    {
        return $this->product()?->title ?? '—';
    }

    public function productType(): string
    {
        return $this->product()?->type ?? 'прочее';
    }

    /**
     * Спред между средней продажей и средней покупкой.
     */
    public function spread(): ?int
    {
        if ($this->sell_avg === null || $this->buy_avg === null) {
            return null;
        }

        return $this->sell_avg - $this->buy_avg;
    }
}
