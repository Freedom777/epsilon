<?php

namespace App\Enums;

enum ItemTypeEnum: string
{
    case ACCESSORY = 'аксессуар';
    case ARMOR     = 'доспех';
    case TOOL      = 'инструмент';
    case NECKLACE  = 'колье';
    case RING      = 'кольцо';
    case WEAPON    = 'оружие';
    case GLOVES    = 'перчатки';
    case RELIC     = 'реликвия';
    case BOOTS     = 'сапоги';
    case TALISMAN  = 'талисман';
    case HELMET    = 'шлем';
    case SHIELD    = 'щит';

    public function label(): string
    {
        return match($this) {
            self::ACCESSORY => '🌂 Аксессуар',
            self::ARMOR     => '🎽 Доспех',
            self::TOOL      => '🔧 Инструмент',
            self::NECKLACE  => '📿 Колье',
            self::RING      => '💍 Кольцо',
            self::WEAPON    => '🔪 Оружие',
            self::GLOVES    => '🧤 Перчатки',
            self::RELIC     => '🏺 Реликвия',
            self::BOOTS     => '🥾 Сапоги',
            self::TALISMAN  => '🎐 Талисман',
            self::HELMET    => '🎩 Шлем',
            self::SHIELD    => '🛡 Щит',
        };
    }

    public static function fromRaw(string $raw): self
    {
        $stripped = mb_strtolower(trim(preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', '', $raw)));
        return self::from($stripped);
    }
}
