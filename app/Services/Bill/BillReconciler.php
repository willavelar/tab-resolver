<?php

namespace App\Services\Bill;

use App\Enums\ItemCategory;
use Illuminate\Support\Str;

class BillReconciler
{
    /**
     * Allowed rounding drift (in BRL) when checking the per-person sum against the total.
     */
    private const TOLERANCE = 0.02;

    /**
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: string}>  $items
     * @param  array<int, array{id: string, name: string}>  $participants
     * @param  array<int, array{participant_id: string, items: array<int, array{name: string, quantity: float}>}>  $claims
     */
    public function reconcile(
        array $items,
        array $participants,
        array $claims,
        bool $foodShared,
        float $serviceChargePercentage,
        float $total,
        bool $forceFinal,
    ): SplitResult {
        if ($participants === []) {
            return SplitResult::requestInput(
                [$this->question('Nenhum participante enviou o que consumiu. Não é possível dividir a conta.')],
                ['reason' => 'no_participants'],
            );
        }

        $catalog = [];
        foreach ($items as $item) {
            $key = $this->normalize($item['name']);
            $qty = (float) $item['quantity'];
            $lineValue = round($qty * (float) $item['unit_price'], 2);

            if (isset($catalog[$key])) {
                $catalog[$key]['remaining'] += $qty;
                $catalog[$key]['total_qty'] += $qty;
                $catalog[$key]['value'] += $lineValue;
            } else {
                $catalog[$key] = [
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'remaining' => $qty,
                    'total_qty' => $qty,
                    'value' => $lineValue,
                ];
            }
        }

        // Effective unit price per item (handles duplicate lines via a weighted value).
        foreach ($catalog as $key => $entry) {
            $catalog[$key]['unit_price'] = $entry['total_qty'] > 0
                ? round($entry['value'] / $entry['total_qty'], 2)
                : 0.0;
        }

        $consumed = [];
        foreach ($participants as $p) {
            $consumed[$p['id']] = ['name' => $p['name'], 'items' => [], 'amount' => 0.0];
        }

        $questions = [];

        foreach ($claims as $claim) {
            $pid = $claim['participant_id'];
            if (! isset($consumed[$pid])) {
                continue;
            }

            foreach ($claim['items'] as $line) {
                $key = $this->normalize($line['name']);
                $qty = (float) $line['quantity'];

                if (! isset($catalog[$key])) {
                    $questions[] = $this->question(
                        "O item \"{$line['name']}\" atribuído a {$consumed[$pid]['name']} não está na conta. O que ele consumiu de fato?"
                    );

                    continue;
                }

                $catalog[$key]['remaining'] -= $qty;
                $lineTotal = round($qty * $catalog[$key]['unit_price'], 2);
                $consumed[$pid]['amount'] = round($consumed[$pid]['amount'] + $lineTotal, 2);
                $consumed[$pid]['items'][] = [
                    'name' => $catalog[$key]['name'],
                    'quantity' => $qty,
                    'unit_price' => $catalog[$key]['unit_price'],
                    'total_price' => $lineTotal,
                    'category' => $catalog[$key]['category'],
                ];
            }
        }

        foreach ($catalog as $entry) {
            if ($entry['remaining'] < -0.001) {
                $questions[] = $this->question(
                    "Foi atribuído mais \"{$entry['name']}\" do que existe na conta. Quem consumiu o quê?"
                );
            }
        }

        $sharedFoodValue = 0.0;
        foreach ($catalog as $entry) {
            $left = $entry['remaining'];
            if ($left <= 0.001) {
                continue;
            }

            $value = round($left * $entry['unit_price'], 2);
            $isFood = $entry['category'] === ItemCategory::Food->value;

            if ($forceFinal) {
                $sharedFoodValue = round($sharedFoodValue + $value, 2);

                continue;
            }

            if ($isFood && $foodShared) {
                $sharedFoodValue = round($sharedFoodValue + $value, 2);

                continue;
            }

            $kind = $isFood ? 'comida' : 'bebida';
            $questions[] = $this->question(
                "Sobrou {$this->qty($left)}x \"{$entry['name']}\" ({$kind}) sem dono. Quem consumiu?"
            );
        }

        if ($questions !== [] && ! $forceFinal) {
            return SplitResult::requestInput(
                array_values($questions),
                ['leftover_questions' => count($questions)],
            );
        }

        $share = count($participants) > 0
            ? round($sharedFoodValue / count($participants), 2)
            : 0.0;

        $allocations = [];
        $running = 0.0;

        foreach ($participants as $p) {
            $pid = $p['id'];
            $subtotal = round($consumed[$pid]['amount'] + $share, 2);
            $tip = round($subtotal * $serviceChargePercentage / 100, 2);
            $rowTotal = round($subtotal + $tip, 2);

            $allocations[] = [
                'participant_id' => $pid,
                'name' => $consumed[$pid]['name'],
                'items' => $consumed[$pid]['items'],
                'shared_food_share' => $share,
                'subtotal' => $subtotal,
                'tip' => $tip,
                'total' => $rowTotal,
            ];

            $running = round($running + $rowTotal, 2);
        }

        $drift = round($total - $running, 2);
        if (abs($drift) > 0.001 && $allocations !== []) {
            $allocations[count($allocations) - 1]['total'] =
                round($allocations[count($allocations) - 1]['total'] + $drift, 2);
            $running = round($running + $drift, 2);
        }

        if (! $forceFinal && abs($total - $running) > self::TOLERANCE) {
            return SplitResult::requestInput(
                [$this->question(
                    'A soma do que cada um consumiu não fechou com o total da conta. '
                    .'Algum item ficou sem dono ou foi contado a mais?'
                )],
                ['expected_total' => $total, 'computed_total' => $running],
            );
        }

        $raw = [
            'shared_food_value' => $sharedFoodValue,
            'computed_total' => $running,
        ];

        if ($forceFinal && abs($drift) > self::TOLERANCE) {
            $raw['large_drift'] = round($drift, 2);
        }

        return SplitResult::complete($allocations, $raw);
    }

    private function normalize(string $name): string
    {
        return Str::of($name)->lower()->ascii()->squish()->value();
    }

    /**
     * @return array{id: string, prompt: string, type: string, options: array<int, string>}
     */
    private function question(string $prompt): array
    {
        return [
            'id' => (string) Str::uuid(),
            'prompt' => $prompt,
            'type' => 'text',
            'options' => [],
        ];
    }

    private function qty(float $value): string
    {
        return fmod($value, 1.0) === 0.0
            ? (string) (int) $value
            : rtrim(number_format($value, 2, ',', ''), '0');
    }
}
