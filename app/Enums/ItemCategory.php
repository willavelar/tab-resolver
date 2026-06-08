<?php

namespace App\Enums;

enum ItemCategory: string
{
    case Food = 'food';
    case Drink = 'drink';

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Comida',
            self::Drink => 'Bebida',
        };
    }
}
