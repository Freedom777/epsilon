<?php

namespace App\Enums;

enum MainStatusEnum: string
{
    case PROCESS = 'process';
    case EMPTY   = 'empty';
    case ERROR   = 'error';
    case OK      = 'ok';

    public function label(): string
    {
        return match($this) {
            self::PROCESS   => 'В обработке',
            self::EMPTY     => 'Пусто',
            self::ERROR     => 'Ошибка',
            self::OK        => 'Обработан',
        };
    }
}
