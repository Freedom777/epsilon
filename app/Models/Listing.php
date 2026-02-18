<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Listing extends Model
{
    protected $fillable = [
        'tg_message_id',
        'tg_user_id',
        'product_id',
        'type',
        'price',
        'currency',
        'quantity',
        'enhancement',
        'durability_current',
        'durability_max',
        'posted_at',
        'status',
        'anomaly_reason',
    ];

    protected $casts = [
        'posted_at'          => 'datetime',
        'price'              => 'integer',
        'quantity'           => 'integer',
        'enhancement'        => 'integer',
        'durability_current' => 'integer',
        'durability_max'     => 'integer',
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
        return $this->belongsTo(Product::class);
    }

    public function scopeRecentDays($query, int $days = 30)
    {
        return $query->where('posted_at', '>=', now()->subDays($days));
    }

    public function scopeWithPrice($query)
    {
        return $query->whereNotNull('price')->where('status', '!=', 'invalid');
    }
}
