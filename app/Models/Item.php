<?php

namespace App\Models;

use App\Enums\ItemGradeEnum;
use App\Enums\ItemRarityEnum;
use App\Enums\ItemSubtypeEnum;
use App\Enums\ItemTypeEnum;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected $fillable = [
        'id',
        'raw_response',
        'title',
        'description',
        'type',
        'subtype',
        'grade',
        'rarity',
        'extra',
        'durability_max',
        'is_personal',
        'is_event',
        'price',
        'status',
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
}
