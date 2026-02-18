<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceListing extends Model
{
    protected $fillable = [
        'tg_message_id',
        'tg_user_id',
        'service_id',
        'type',
        'price',
        'currency',
        'description',
        'posted_at',
        'status',
        'anomaly_reason',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
        'price'     => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TgMessage::class, 'tg_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TgUser::class, 'tg_user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
