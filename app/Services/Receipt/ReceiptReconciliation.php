<?php

namespace App\Services\Receipt;

/**
 * Verificação aritmética determinística da leitura da conta.
 *
 * Roda DEPOIS que a IA devolve um resultado "complete" e confere se a conta
 * fecha: linhas, soma dos itens vs. subtotal e subtotal + gorjeta vs. total.
 * Quando algo não bate (além da tolerância), devolve perguntas no mesmo
 * formato consumido pelo fluxo de esclarecimento — pode ter sido leitura errada.
 */
class ReceiptReconciliation
{
    /**
     * @param  array<int, array{name: string, quantity: float, unit_price: float, total_price: float, category: string}>  $items
     * @return array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>
     */
    public static function check(
        array $items,
        float $subtotal,
        float $serviceCharge,
        float $total,
        float $tolerance = 0.05,
    ): array {
        $questions = [];

        foreach ($items as $index => $item) {
            $expected = (float) $item['quantity'] * (float) $item['unit_price'];
            $lineTotal = (float) $item['total_price'];

            if (abs($expected - $lineTotal) > $tolerance) {
                $questions[] = self::question(
                    "recon_line_{$index}",
                    sprintf(
                        'No item "%s", quantidade (%s) × preço unitário (%s) dá %s, mas o total da linha lido foi %s. Pode ter sido leitura errada. Como devo corrigir?',
                        $item['name'],
                        self::money((float) $item['quantity']),
                        self::money((float) $item['unit_price']),
                        self::money($expected),
                        self::money($lineTotal),
                    ),
                );
            }
        }

        $itemsSum = array_sum(array_map(fn ($item) => (float) $item['total_price'], $items));

        if (abs($itemsSum - $subtotal) > $tolerance) {
            $questions[] = self::question(
                'recon_subtotal',
                sprintf(
                    'A soma dos itens (%s) não confere com o subtotal lido (%s) — diferença de %s. Pode ter sido leitura errada. Como devo corrigir?',
                    self::money($itemsSum),
                    self::money($subtotal),
                    self::money(abs($itemsSum - $subtotal)),
                ),
            );
        }

        if (abs($subtotal + $serviceCharge - $total) > $tolerance) {
            $questions[] = self::question(
                'recon_total',
                sprintf(
                    'O subtotal (%s) mais a taxa de serviço (%s) dá %s, mas o total lido foi %s — diferença de %s. Pode ter sido leitura errada. Como devo corrigir?',
                    self::money($subtotal),
                    self::money($serviceCharge),
                    self::money($subtotal + $serviceCharge),
                    self::money($total),
                    self::money(abs($subtotal + $serviceCharge - $total)),
                ),
            );
        }

        return $questions;
    }

    /**
     * @return array{id: string, prompt: string, type: string, options: array<int, string>}
     */
    private static function question(string $id, string $prompt): array
    {
        return ['id' => $id, 'prompt' => $prompt, 'type' => 'text', 'options' => []];
    }

    private static function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }
}
