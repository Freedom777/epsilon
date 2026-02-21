<?php

namespace App\Models;

use App\Support\Utf8Helper;
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

    public static function normalizeName(?string $name): string
    {
        $name = Utf8Helper::clean($name);

        if (blank($name)) {
            return '';
        }
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return mb_strtolower($name);
    }

    public static function findOrCreateByName(string $rawName, ?string $icon = null): self
    {
        $cleanName = Utf8Helper::clean($rawName);
        if (blank($cleanName)) {
            // Возвращаем заглушку вместо падения
            return static::firstOrCreate(
                ['normalized_name' => '_unknown'],
                ['name' => '_unknown', 'status' => 'ok']
            );
        }

        $normalized = static::normalizeName($cleanName);

        $service = static::where('normalized_name', $normalized)->first();

        if ($service) {
            if (!$service->icon && $icon) {
                $service->update(['icon' => $icon]);
            }
            return $service;
        }

        return static::create([
            'icon'            => $icon,
            'name'            => $cleanName,  // чистое имя, без мусорных байтов
            'normalized_name' => $normalized,
            'status'          => 'ok',
        ]);
    }
}
