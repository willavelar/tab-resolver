<?php

namespace App\Services\Receipt;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PrismReceiptExtractor implements ReceiptExtractor
{
    public function extract(string $absoluteImagePath): ExtractionResult
    {
        $schema = new ObjectSchema(
            name: 'receipt',
            description: 'Itens e totais extraídos da conta de um restaurante/bar',
            properties: [
                new ArraySchema(
                    name: 'items',
                    description: 'Lista de itens consumidos na conta',
                    items: new ObjectSchema(
                        name: 'item',
                        description: 'Um item da conta',
                        properties: [
                            new StringSchema('name', 'Nome do item'),
                            new NumberSchema('quantity', 'Quantidade do item'),
                            new NumberSchema('unit_price', 'Preço unitário do item'),
                            new NumberSchema('total_price', 'Preço total da linha (quantidade x unitário)'),
                        ],
                        requiredFields: ['name', 'quantity', 'unit_price', 'total_price'],
                    ),
                ),
                new NumberSchema('subtotal', 'Subtotal dos itens, sem taxa'),
                new NumberSchema('service_charge', 'Taxa de serviço (ex.: 10%)'),
                new NumberSchema('total', 'Total geral da conta'),
            ],
            requiredFields: ['items', 'subtotal', 'service_charge', 'total'],
        );

        $message = new UserMessage(
            'Leia esta conta de restaurante/bar e extraia todos os itens com nome, '
            .'quantidade, preço unitário e preço total, além do subtotal, da taxa de '
            .'serviço e do total geral. Use números (sem símbolo de moeda). Se a taxa '
            .'de serviço não existir, use 0.',
            [Image::fromLocalPath(path: $absoluteImagePath)],
        );

        $response = Prism::structured()
            ->using(Provider::Anthropic, config('services.anthropic.receipt_model'))
            ->withSchema($schema)
            ->withProviderOptions(['use_tool_calling' => true])
            ->withMessages([$message])
            ->asStructured();

        /** @var array{items: array<int, array<string, mixed>>, subtotal: mixed, service_charge: mixed, total: mixed} $data */
        $data = $response->structured;

        $items = array_map(fn (array $item): array => [
            'name' => (string) $item['name'],
            'quantity' => (float) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'total_price' => (float) $item['total_price'],
        ], $data['items'] ?? []);

        return new ExtractionResult(
            items: $items,
            subtotal: (float) ($data['subtotal'] ?? 0),
            serviceCharge: (float) ($data['service_charge'] ?? 0),
            total: (float) ($data['total'] ?? 0),
            raw: $data,
        );
    }
}
