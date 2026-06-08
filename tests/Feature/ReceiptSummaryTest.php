<?php

use App\Enums\ExtractionStatus;
use App\Enums\ItemCategory;
use App\Models\Session;
use App\Models\SessionItem;
use App\Models\User;
use App\Services\Receipt\ReceiptSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it builds a grouped markdown summary matching the template', function () {
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'subtotal' => 363.40,
        'service_charge' => 36.34,
        'service_charge_percentage' => 10,
        'total' => 399.74,
    ]);

    SessionItem::create(['bill_session_id' => $session->id, 'name' => 'Parmegiana', 'quantity' => 1, 'unit_price' => 119.90, 'total_price' => 119.90, 'category' => ItemCategory::Food, 'position' => 1]);
    SessionItem::create(['bill_session_id' => $session->id, 'name' => 'Heineken', 'quantity' => 3, 'unit_price' => 9.90, 'total_price' => 29.70, 'category' => ItemCategory::Drink, 'position' => 2]);

    $md = ReceiptSummary::for($session->fresh());

    expect($md)->toContain('# Consumidos')
        ->and($md)->toContain('## Comida')
        ->and($md)->toContain('- 1 x Parmegiana (R$ 119,90) - R$ 119,90')
        ->and($md)->toContain('## Bebida')
        ->and($md)->toContain('- 3 x Heineken (R$ 9,90) - R$ 29,70')
        ->and($md)->toContain('- Sub-total: R$ 363,40')
        ->and($md)->toContain('- Gorjeta (10%): R$ 36,34')
        ->and($md)->toContain('- Total: R$ 399,74');
});

test('it omits an empty category section and the tip line when there is no charge', function () {
    $session = Session::factory()->for(User::factory())->create([
        'status' => ExtractionStatus::Completed,
        'subtotal' => 20.0,
        'service_charge' => 0,
        'service_charge_percentage' => null,
        'total' => 20.0,
    ]);

    SessionItem::create(['bill_session_id' => $session->id, 'name' => 'Água', 'quantity' => 1, 'unit_price' => 20.0, 'total_price' => 20.0, 'category' => ItemCategory::Drink, 'position' => 1]);

    $md = ReceiptSummary::for($session->fresh());

    expect($md)->not->toContain('## Comida')
        ->and($md)->toContain('## Bebida')
        ->and($md)->not->toContain('Gorjeta');
});
