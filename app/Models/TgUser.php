<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TgUser extends Model
{
    protected $fillable = [
        'tg_id',
        'username',
        'display_name',
        'first_name',
        'last_name',
    ];

    protected $casts = [
        'tg_id' => 'integer',
    ];

    /**
     * Ссылка на профиль пользователя в Telegram.
     * Возможна только если есть username.
     */
    public function getTgProfileLinkAttribute(): ?string
    {
        if ($this->username) {
            return 'https://t.me/' . ltrim($this->username, '@');
        }
        return null;
    }

    /**
     * Отображаемое имя: ник или имя.
     */
    public function getDisplayAttribute(): string
    {
        return $this->username
            ? '@' . ltrim($this->username, '@')
            : ($this->display_name ?? $this->first_name ?? "user_{$this->tg_id}");
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TgMessage::class);
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
