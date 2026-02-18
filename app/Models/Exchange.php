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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function exchangeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'exchange_product_id');
    }
}
