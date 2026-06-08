<?php

namespace App\Services\Receipt;

use App\Enums\ItemCategory;
use App\Models\Session;

class ReceiptSummary
{
    public static function for(Session $session): string
    {
        $session->loadMissing('items');

        $lines = ['# Consumidos', ''];

        foreach ([ItemCategory::Food, ItemCategory::Drink] as $category) {
            $items = $session->items->filter(fn ($item) => $item->category === $category);

            if ($items->isEmpty()) {
                continue;
            }

            $lines[] = '## '.$category->label();

            foreach ($items as $item) {
                $lines[] = sprintf(
                    '- %s x %s (%s) - %s',
                    self::quantity($item->quantity),
                    $item->name,
                    self::brl($item->unit_price),
                    self::brl($item->total_price),
                );
            }

            $lines[] = '';
        }

        $lines[] = '# Valores totais';
        $lines[] = '- Sub-total: '.self::brl($session->subtotal);

        if ((float) $session->service_charge > 0) {
            $lines[] = '- Gorjeta'.self::percentageSuffix($session->service_charge_percentage).': '.self::brl($session->service_charge);
        }

        $lines[] = '- Total: '.self::brl($session->total);

        return implode("\n", $lines);
    }

    private static function quantity(int|float|string $value): string
    {
        $value = (float) $value;

        return fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : rtrim(number_format($value, 2, ',', ''), '0');
    }

    private static function percentageSuffix(int|float|string|null $percentage): string
    {
        if ($percentage === null) {
            return '';
        }

        $value = (float) $percentage;
        $formatted = fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : number_format($value, 1, ',', '');

        return ' ('.$formatted.'%)';
    }

    private static function brl(int|float|string|null $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }
}
