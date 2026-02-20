<?php

namespace App\Enums;

enum ItemRarityEnum: string
{
    case LEGENDARY = 'легендарная';
    case COMMON    = 'обычная';
    case RARE      = 'редкая';
    case EPIC      = 'эпическая';

    public function label(): string
    {
        return match($this) {
            self::LEGENDARY => '🟡 Легендарная',
            self::COMMON    => '⚪ Обычная',
            self::RARE      => '🔵 Редкая',
            self::EPIC      => '🟣 Эпическая',
        };
    }
}
