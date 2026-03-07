<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobAssetDrop extends Model
{
    protected $fillable = [
        'mob_id',
        'asset_id',
    ];
}
