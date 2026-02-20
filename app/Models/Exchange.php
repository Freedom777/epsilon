<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exchange extends Model
{
    protected $fillable = [
        'tg_message_id',
        'tg_user_id',
        'product_id',
        'product_quantity',
        'exchange_product_id',
        'exchange_product_quantity',
        'surcharge_amount',
        'surcharge_currency',
        'surcharge_direction',
        'posted_at',
    ];

    protected $casts = [
        'posted_at'                 => 'datetime',
        'product_quantity'          => 'integer',
        'exchange_product_quantity' => 'integer',
        'surcharge_amount'          => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TgMessage::class, 'tg_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TgUser::class, 'tg_user_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function exchangeAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'exchange_asset_id');
    }

    public function exchangeItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'exchange_item_id');
    }
}
