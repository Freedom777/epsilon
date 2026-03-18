<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id',
        'title',
        'normalized_title',
        'type',
        'subtype',
        'status',
        'description',
        'raw_response',
    ];

    protected $casts = [
        'id' => 'integer',
    ];

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
