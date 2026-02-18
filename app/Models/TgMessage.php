<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TgMessage extends Model
{
    protected $fillable = [
        'tg_message_id',
        'tg_chat_id',
        'tg_user_id',
        'raw_text',
        'tg_link',
        'sent_at',
        'is_parsed',
    ];

    protected $casts = [
        'sent_at'   => 'datetime',
        'is_parsed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TgUser::class, 'tg_user_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function exchanges(): HasMany
    {
        return $this->hasMany(Exchange::class);
    }

    public function serviceListings(): HasMany
    {
        return $this->hasMany(ServiceListing::class);
    }
}
