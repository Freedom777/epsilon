<?php

namespace App\Enums;

enum AssetSkillTypeEnum: string
{
    case ACTIVE        = 'активный';
    case PASSIVE       = 'пассивный';

    public function label(): string
    {
        return match($this) {
            self::ACTIVE      => 'Активный',
            self::PASSIVE     => 'Пассивный',
        };
    }
}
