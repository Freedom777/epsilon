<?php

namespace App\Enums;

enum ItemSubtypeEnum: string
{
    case DAGGER_TWO    = 'кинжал двуручный';
    case PICKAXE       = 'кирка';
    case BOW           = 'лук';
    case SWORD_TWO     = 'меч двуручный';
    case HAMMER_TWO    = 'молот двуручный';
    case HOE           = 'мотыга';
    case DAGGER_ONE    = 'одноручный кинжал';
    case SWORD_ONE     = 'одноручный меч';
    case HAMMER_ONE    = 'одноручный молот';
    case AXE_ONE       = 'одноручный топор';
    case HUNTING_BOW   = 'охотничий лук';
    case KNUCKLES      = 'парные кастеты';
    case STAFF         = 'посох';
    case AXE_TWO       = 'топор двуручный';
    case ROD           = 'удочка';

    public function label(): string
    {
        return match($this) {
            self::DAGGER_TWO  => 'Кинжал двуручный',
            self::PICKAXE     => 'Кирка',
            self::BOW         => 'Лук',
            self::SWORD_TWO   => 'Меч двуручный',
            self::HAMMER_TWO  => 'Молот двуручный',
            self::HOE         => 'Мотыга',
            self::DAGGER_ONE  => 'Одноручный кинжал',
            self::SWORD_ONE   => 'Одноручный меч',
            self::HAMMER_ONE  => 'Одноручный молот',
            self::AXE_ONE     => 'Одноручный топор',
            self::HUNTING_BOW => 'Охотничий лук',
            self::KNUCKLES    => 'Парные кастеты',
            self::STAFF       => 'Посох',
            self::AXE_TWO     => 'Топор двуручный',
            self::ROD         => 'Удочка',
        };
    }
}
