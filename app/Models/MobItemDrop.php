<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobItemDrop extends Model
{
    protected $fillable = [
        'mob_id',
        'item_id',
    ];
}
