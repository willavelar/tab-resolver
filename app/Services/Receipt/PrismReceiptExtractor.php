<?php

namespace App\Services\Receipt;

use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class PrismReceiptExtractor implements ReceiptExtractor
{
    public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult
    {
        Log::info('[Service][PrismReceiptExtractor][extract] Inicio da execusão.', [
            'respostas_anteriores' => count($answered),
            'force_final' => $forceFinal,
        ]);

        $schema = new ObjectSchema(
            name: 'receipt',
            description: 'Resultado da leitura da conta: itens finais OU perguntas quando houver dúvida',
            properties: [
                new EnumSchema('status', 'Use "needs_input" se tiver QUALQUER dúvida; senão "complete"', ['complete', 'needs_input']),
                new ArraySchema(
                    name: 'questions',
                    description: 'Perguntas ao usuário quando status = needs_input (senão lista vazia)',
                    items: new ObjectSchema(
                        name: 'question',
                        description: 'Uma pergunta de esclarecimento',
                        properties: [
                            new StringSchema('id', 'Identificador curto e único da pergunta (ex.: q1)'),
                            new StringSchema('prompt', 'A pergunta em português'),
                            new EnumSchema('type', 'choice para escolha entre opções, text para resposta livre', ['choice', 'text']),
                            new ArraySchema('options', 'Opções quando type = choice (senão vazio)', new StringSchema('option', 'Uma opção')),
                        ],
                        requiredFields: ['id', 'prompt', 'type', 'options'],
                    ),
                ),
                new ArraySchema(
                    name: 'items',
                    description: 'Itens consumidos quando status = complete (senão lista vazia)',
                    items: new ObjectSchema(
                        name: 'item',
                        description: 'Um item da conta',
                        properties: [
                            new StringSchema('name', 'Nome do item'),
                            new NumberSchema('quantity', 'Quantidade do item'),
                            new NumberSchema('unit_price', 'Preço unitário do item'),
                            new NumberSchema('total_price', 'Preço total da linha (quantidade x unitário)'),
                            new EnumSchema('category', 'food para comida, drink para bebida', ['food', 'drink']),
                        ],
                        requiredFields: ['name', 'quantity', 'unit_price', 'total_price', 'category'],
                    ),
                ),
                new NumberSchema('subtotal', 'Subtotal dos itens, sem taxa'),
                new NumberSchema('service_charge', 'Taxa de serviço / gorjeta SOMENTE se impressa na conta (valor absoluto). Use 0 se a linha não existir — nunca assuma os 10% habituais.'),
                new NumberSchema('service_charge_percentage', 'Percentual da gorjeta apenas quando impresso na conta. Use 0 se não houver linha de serviço — não invente 10%.'),
                new NumberSchema('total', 'Total geral da conta'),
            ],
            requiredFields: ['status', 'questions', 'items', 'subtotal', 'service_charge', 'service_charge_percentage', 'total'],
        );

        $prompt = $this->buildPrompt($answered, $forceFinal);

        $message = new UserMessage($prompt, [Image::fromLocalPath(path: $absoluteImagePath)]);

        $model = $this->resolveCredentials()['model'];

        Log::info('[Service][PrismReceiptExtractor][extract] Enviando imagem para o modelo.', [
            'model' => $model,
        ]);

        $response = Prism::structured()
            ->using(Provider::OpenAI, $model)
            ->withSchema($schema)
            ->withProviderOptions(['use_tool_calling' => true])
            ->withMessages([$message])
            ->asStructured();

        /** @var array<string, mixed> $data */
        $data = $response->structured;

        if (($data['status'] ?? 'complete') === 'needs_input' && ! $forceFinal) {
            $questions = array_map(fn (array $q): array => [
                'id' => (string) ($q['id'] ?? Str::uuid()),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'type' => in_array($q['type'] ?? 'text', ['choice', 'text'], true) ? $q['type'] : 'text',
                'options' => array_values(array_map('strval', $q['options'] ?? [])),
            ], $data['questions'] ?? []);

            $partialItems = array_map(fn (array $item): array => [
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'total_price' => (float) ($item['total_price'] ?? 0),
                'category' => in_array($item['category'] ?? null, ['food', 'drink'], true) ? $item['category'] : 'food',
            ], $data['items'] ?? []);

            Log::info('[Service][PrismReceiptExtractor][extract] Modelo retornou perguntas de esclarecimento. Fim da execusão.', [
                'perguntas' => count($questions),
                'itens_parciais' => count($partialItems),
            ]);

            return ExtractionResult::requestInput(
                questions: $questions,
                raw: $data,
                items: $partialItems,
                subtotal: (float) ($data['subtotal'] ?? 0),
                serviceCharge: (float) ($data['service_charge'] ?? 0),
                total: (float) ($data['total'] ?? 0),
            );
        }

        $items = array_map(fn (array $item): array => [
            'name' => (string) $item['name'],
            'quantity' => (float) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'total_price' => (float) $item['total_price'],
            'category' => in_array($item['category'] ?? null, ['food', 'drink'], true) ? $item['category'] : 'food',
        ], $data['items'] ?? []);

        $percentage = (float) ($data['service_charge_percentage'] ?? 0);

        Log::info('[Service][PrismReceiptExtractor][extract] Extração completa. Fim da execusão.', [
            'itens' => count($items),
            'subtotal' => (float) ($data['subtotal'] ?? 0),
            'total' => (float) ($data['total'] ?? 0),
        ]);

        return ExtractionResult::complete(
            items: $items,
            subtotal: (float) ($data['subtotal'] ?? 0),
            serviceCharge: (float) ($data['service_charge'] ?? 0),
            serviceChargePercentage: $percentage > 0 ? $percentage : null,
            total: (float) ($data['total'] ?? 0),
            raw: $data,
        );
    }

    /**
     * Monta o prompt enviado ao modelo. Extraído para um método puro para que o
     * guarda-corpo da gorjeta (não inventar os 10% habituais) seja testável.
     *
     * @param  array<int, array{question: string, answer: string}>  $answered
     */
    public function buildPrompt(array $answered = [], bool $forceFinal = false): string
    {
        $prompt = 'Leia esta conta de restaurante/bar. Para cada item informe nome, '
            .'quantidade, preço unitário, preço total e a categoria (food para comida, '
            .'drink para bebida). Informe também subtotal e total. Use números (sem '
            .'símbolo de moeda). '
            .'GORJETA / TAXA DE SERVIÇO: ela só existe se estiver IMPRESSA como uma linha '
            .'na conta (pode aparecer como "Serviço", "Taxa de serviço", "Serv." ou '
            .'"Gorjeta"). NUNCA assuma os 10% habituais nem invente uma gorjeta que não '
            .'esteja escrita no recibo. Se essa linha NÃO existir, use 0 em service_charge '
            .'e em service_charge_percentage. Quando existir, informe o valor e o '
            .'percentual exatamente como impressos. '
            .'NÃO ADIVINHE: se tiver qualquer '
            .'dúvida sobre a categoria de um item ou não conseguir ler um valor, retorne '
            .'status "needs_input" com perguntas objetivas em "questions" (uma por dúvida). '
            .'MESMO ao perguntar, preencha "items", subtotal, taxa e total com tudo o que '
            .'você JÁ leu com confiança (parcial) — isso serve para mostrar ao usuário o que '
            .'você já entendeu, mas NÃO substitui as perguntas. Caso contrário, status "complete".';

        if ($answered !== []) {
            $prompt .= "\n\nO usuário já respondeu às seguintes dúvidas — use estas respostas:\n";
            foreach ($answered as $qa) {
                $prompt .= '- '.$qa['question'].' => '.$qa['answer']."\n";
            }
        }

        if ($forceFinal) {
            $prompt .= "\n\nEsta é a rodada final: NÃO faça mais perguntas. Use seu melhor "
                .'julgamento e retorne status "complete" com todos os itens classificados.';
        }

        return $prompt;
    }

    /**
     * Resolve a chave/modelo da integração: usa o registro do banco quando
     * existir (sobrescrevendo o config do Prism em runtime), senão cai no .env.
     *
     * @return array{model: string}
     */
    public function resolveCredentials(): array
    {
        $integration = Integration::current();

        if (filled($integration->api_key)) {
            config(['prism.providers.openai.api_key' => $integration->api_key]);
            Log::info('[Service][PrismReceiptExtractor][resolveCredentials] Usando API key da integração (banco).');
        }

        $model = filled($integration->receipt_model)
            ? $integration->receipt_model
            : config('services.openai.receipt_model');

        Log::info('[Service][PrismReceiptExtractor][resolveCredentials] Modelo resolvido.', [
            'model' => $model,
            'fonte' => filled($integration->receipt_model) ? 'integração' : 'config',
        ]);

        return ['model' => $model];
    }
}
