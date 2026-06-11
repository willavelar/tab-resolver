<?php

namespace App\Services\Bill;

use App\Models\Integration;
use App\Models\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Audio;

class PrismBillSplitter implements BillSplitter
{
    public function split(Session $session, array $participants, bool $foodShared, bool $othersShared = false, array $answered = [], bool $forceFinal = false): SplitResult
    {
        Log::info('[Service][PrismBillSplitter][split] Inicio da execusão.', [
            'session_id' => $session->id,
            'participantes' => count($participants),
            'food_shared' => $foodShared,
            'others_shared' => $othersShared,
            'force_final' => $forceFinal,
        ]);

        $session->loadMissing('items', 'participants');

        $transcripts = $this->transcribeParticipants($session);

        $claims = $this->matchClaims($session, $transcripts, $answered, $forceFinal);

        if ($claims->needsInput() && ! $forceFinal) {
            return $claims;
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
            claims: $claims->raw['claims'] ?? [],
            foodShared: $foodShared,
            othersShared: $othersShared,
            serviceChargePercentage: (float) $session->service_charge_percentage,
            total: (float) $session->total,
            forceFinal: $forceFinal,
        );
    }

    /**
     * @return array<string, string>
     */
    private function transcribeParticipants(Session $session): array
    {
        $creds = $this->resolveCredentials();
        $out = [];

        foreach ($session->participants as $participant) {
            $text = (string) $participant->text;

            if (filled($participant->audio_path) && blank($participant->transcript)) {
                try {
                    $path = Storage::disk('public')->path($participant->audio_path);
                    $response = Prism::audio()
                        ->using(Provider::OpenAI, $creds['audio_model'])
                        ->withInput(Audio::fromLocalPath($path))
                        ->asText();
                    $participant->forceFill(['transcript' => $response->text])->save();
                } catch (\Throwable $e) {
                    Log::warning('[Service][PrismBillSplitter][transcribeParticipants] Falha ao transcrever áudio.', [
                        'participant_id' => $participant->id,
                        'erro' => $e->getMessage(),
                    ]);
                }
            }

            $out[$participant->id] = trim($text.' '.(string) $participant->transcript);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $transcripts
     * @param  array<int, array{question: string, answer: string}>  $answered
     */
    private function matchClaims(Session $session, array $transcripts, array $answered, bool $forceFinal): SplitResult
    {
        $schema = new ObjectSchema(
            name: 'split',
            description: 'Atribuição do que cada participante consumiu, OU perguntas quando houver dúvida',
            properties: [
                new EnumSchema('status', 'Use "needs_input" se tiver QUALQUER dúvida; senão "complete"', ['complete', 'needs_input']),
                new ArraySchema(
                    name: 'questions',
                    description: 'Perguntas ao dono quando status = needs_input (senão lista vazia)',
                    items: new ObjectSchema(
                        name: 'question',
                        description: 'Uma pergunta de esclarecimento',
                        properties: [
                            new StringSchema('id', 'Identificador curto e único (ex.: q1)'),
                            new StringSchema('prompt', 'A pergunta em português'),
                            new EnumSchema('type', 'choice ou text', ['choice', 'text']),
                            new ArraySchema('options', 'Opções quando type = choice (senão vazio)', new StringSchema('option', 'Uma opção')),
                        ],
                        requiredFields: ['id', 'prompt', 'type', 'options'],
                    ),
                ),
                new ArraySchema(
                    name: 'claims',
                    description: 'O que cada participante consumiu quando status = complete (senão vazio)',
                    items: new ObjectSchema(
                        name: 'claim',
                        description: 'Itens consumidos por um participante',
                        properties: [
                            new StringSchema('participant_id', 'ID do participante'),
                            new ArraySchema(
                                name: 'items',
                                description: 'Itens que esta pessoa consumiu',
                                items: new ObjectSchema(
                                    name: 'claim_item',
                                    description: 'Um item consumido',
                                    properties: [
                                        new StringSchema('name', 'Nome do item exatamente como aparece na conta'),
                                        new NumberSchema('quantity', 'Quantidade consumida por esta pessoa'),
                                    ],
                                    requiredFields: ['name', 'quantity'],
                                ),
                            ),
                        ],
                        requiredFields: ['participant_id', 'items'],
                    ),
                ),
            ],
            requiredFields: ['status', 'questions', 'claims'],
        );

        $itemLines = $session->items
            ->map(fn ($i) => "- {$i->name} (qtd {$i->quantity}, {$i->category?->value})")
            ->implode("\n");

        $peopleLines = collect($transcripts)
            ->map(function (string $said, string $id) use ($session) {
                $name = $session->participants->firstWhere('id', $id)?->name ?? $id;

                return "- participant_id={$id} nome={$name}: \"{$said}\"";
            })
            ->implode("\n");

        $prompt = "Você está dividindo uma conta de bar/restaurante. Itens da conta:\n{$itemLines}\n\n"
            ."Cada participante disse o que consumiu:\n{$peopleLines}\n\n"
            .'Para cada participante, liste em "claims" os itens que ELE consumiu, usando o '
            .'nome EXATO do item na conta e a quantidade. NÃO calcule valores nem gorjeta. '
            .'NÃO invente: se algo estiver ambíguo (ex.: alguém citou um item que não está na '
            .'conta, ou uma quantidade não bate), retorne status "needs_input" com perguntas '
            .'objetivas em "questions". MESMO ao perguntar, preencha "claims" com as atribuições '
            .'de que você JÁ tem certeza (parcial) — serve para mostrar ao dono o que você já '
            .'entendeu, mas NÃO substitui as perguntas. Caso contrário, status "complete".';

        if ($answered !== []) {
            $prompt .= "\n\nO dono já respondeu:\n";
            foreach ($answered as $qa) {
                $prompt .= '- '.$qa['question'].' => '.$qa['answer']."\n";
            }
        }

        if ($forceFinal) {
            $prompt .= "\n\nEsta é a rodada final: NÃO faça mais perguntas. Use seu melhor julgamento "
                .'e retorne status "complete" com os claims que conseguir inferir.';
        }

        $response = Prism::structured()
            ->using(Provider::OpenAI, $this->resolveCredentials()['model'])
            ->withSchema($schema)
            ->withProviderOptions(['use_tool_calling' => true])
            ->withPrompt($prompt)
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

            return SplitResult::requestInput($questions, $data);
        }

        $claims = array_map(fn (array $c): array => [
            'participant_id' => (string) ($c['participant_id'] ?? ''),
            'items' => array_map(fn (array $i): array => [
                'name' => (string) ($i['name'] ?? ''),
                'quantity' => (float) ($i['quantity'] ?? 0),
            ], $c['items'] ?? []),
        ], $data['claims'] ?? []);

        return SplitResult::complete([], ['claims' => $claims] + $data);
    }

    /**
     * @return array{model: string, audio_model: string}
     */
    private function resolveCredentials(): array
    {
        $integration = Integration::current();

        if (filled($integration->api_key)) {
            config(['prism.providers.openai.api_key' => $integration->api_key]);
        }

        return [
            'model' => filled($integration->receipt_model)
                ? $integration->receipt_model
                : config('services.openai.receipt_model'),
            'audio_model' => filled($integration->audio_model)
                ? $integration->audio_model
                : config('services.openai.audio_model'),
        ];
    }
}
