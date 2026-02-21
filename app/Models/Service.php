<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'parent_id',
        'icon',
        'name',
        'normalized_name',
        'status',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'parent_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(Service::class, 'parent_id');
    }

    public function serviceListings(): HasMany
    {
        return $this->hasMany(ServiceListing::class);
    }

    public static function normalizeName(string $name): string
    {
        if (blank($name)) {
            return '';
        }

        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        $name = preg_replace('/[\x{FFFD}]/u', '', $name) ?? $name;
        $name = preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27FF}]|[\x{2300}-\x{23FF}]/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return mb_strtolower($name);
    }

    public static function findOrCreateByName(string $rawName, ?string $icon = null): self
    {
        $normalized = static::normalizeName($rawName);

        $service = static::where('normalized_name', $normalized)->first();

        if ($service) {
            if (!$service->icon && $icon) {
                $service->update(['icon' => $icon]);
            }
            return $service;
        }

        return static::create([
            'icon'            => $icon,
            'name'            => $rawName,
            'normalized_name' => $normalized,
            'status'          => 'ok',
        ]);
    }
}
