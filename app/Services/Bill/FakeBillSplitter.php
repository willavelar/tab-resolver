<?php

namespace App\Services\Bill;

use App\Models\Session;

class FakeBillSplitter implements BillSplitter
{
    public function split(Session $session, array $participants, bool $foodShared, array $answered = [], bool $forceFinal = false): SplitResult
    {
        $ids = array_column($participants, 'id');
        $claims = [];

        if (isset($ids[0])) {
            $claims[] = ['participant_id' => $ids[0], 'items' => [
                ['name' => 'Moscow Mule', 'quantity' => 1.0],
                ['name' => 'Heineken', 'quantity' => 2.0],
            ]];
        }
        if (isset($ids[1])) {
            $claims[] = ['participant_id' => $ids[1], 'items' => [
                ['name' => 'Heineken', 'quantity' => 1.0],
                ['name' => 'Moscow Mule', 'quantity' => 1.0],
            ]];
        }

        $items = $session->items->map(fn ($i) => [
            'name' => $i->name,
            'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'total_price' => (float) $i->total_price,
            'category' => $i->category?->value ?? 'food',
        ])->all();

        return (new BillReconciler)->reconcile(
            items: $items,
            participants: $participants,
            claims: $claims,
            foodShared: $foodShared,
            serviceChargePercentage: (float) $session->service_charge_percentage,
            total: (float) $session->total,
            forceFinal: $forceFinal,
        );
    }
}
