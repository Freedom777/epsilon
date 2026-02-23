<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobDropIndex extends Model
{
    protected $table = 'mob_drop_index';

    public $timestamps = false;

    protected $fillable = [
        'mob_id',
        'asset_id',
        'drop_text',
        'normalized',
    ];

    public function mob(): BelongsTo
    {
        return $this->belongsTo(Mob::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
