<?php

namespace App\Enums;

enum ItemCategory: string
{
    case Food = 'food';
    case Drink = 'drink';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Comida',
            self::Drink => 'Bebida',
            self::Other => 'Outros',
        };
    }
}
