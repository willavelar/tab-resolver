<?php

use App\Services\Receipt\PrismReceiptExtractor;

it('instructs the model to never assume a default service charge', function () {
    $prompt = (new PrismReceiptExtractor)->buildPrompt();

    // A gorjeta só existe se estiver impressa na conta: o modelo não pode
    // inventar os 10% habituais quando a linha não aparece no recibo.
    expect($prompt)
        ->toContain('NUNCA assuma')
        ->toContain('IMPRESSA')
        ->toContain('use 0');
});

it('tells the model the service charge may be labelled "Serviço"', function () {
    $prompt = (new PrismReceiptExtractor)->buildPrompt();

    expect($prompt)
        ->toContain('Serviço')
        ->toContain('percentual');
});

it('appends answered clarifications and the final-round instruction to the prompt', function () {
    $prompt = (new PrismReceiptExtractor)->buildPrompt(
        answered: [['question' => 'Qual a categoria da água?', 'answer' => 'drink']],
        forceFinal: true,
    );

    expect($prompt)
        ->toContain('Qual a categoria da água? => drink')
        ->toContain('rodada final');
});

it('tells the model about the outros category for non-consumables', function () {
    $prompt = (new PrismReceiptExtractor)->buildPrompt();

    expect($prompt)
        ->toContain('other')
        ->toContain('estacionamento');
});
