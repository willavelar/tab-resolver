<?php

use App\Enums\ItemCategory;

it('exposes the outros category with a PT-BR label', function () {
    expect(ItemCategory::Other->value)->toBe('other')
        ->and(ItemCategory::Other->label())->toBe('Outros');
});

it('still exposes food and drink labels', function () {
    expect(ItemCategory::Food->label())->toBe('Comida')
        ->and(ItemCategory::Drink->label())->toBe('Bebida');
});
