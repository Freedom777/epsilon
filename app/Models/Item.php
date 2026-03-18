<?php

namespace App\Models;

use App\Enums\ItemGradeEnum;
use App\Enums\ItemRarityEnum;
use App\Enums\ItemSubtypeEnum;
use App\Enums\ItemTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id',
        'title',
        'type',
        'subtype',
        'grade',
        'rarity',
        'durability_max',
        'price',
        'is_personal',
        'is_event',
        'status',
        'extra',
        'description',
        'raw_response',
    ];

    protected $casts = [
        'id'                 => 'integer',
        'type_normalized'    => ItemTypeEnum::class,
        'subtype_normalized' => ItemSubtypeEnum::class,
        'rarity_normalized'  => ItemRarityEnum::class,
        'grade_normalized'   => ItemGradeEnum::class,
        'is_personal'        => 'boolean',
        'is_event'           => 'boolean',
    ];

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
