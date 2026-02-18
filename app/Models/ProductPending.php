<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPending extends Model
{
    protected $fillable = [
        'product_id',
        'icon',
        'name',
        'normalized_name',
        'grade',
        'status',
        'tg_message_id',
        'reviewed',
    ];

    protected $casts = [
        'reviewed' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TgMessage::class, 'tg_message_id');
    }
}
