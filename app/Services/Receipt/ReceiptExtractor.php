<?php

namespace App\Services\Receipt;

interface ReceiptExtractor
{
    /**
     * Read a receipt image and return either a completed extraction or a set of
     * clarification questions when the model is unsure.
     *
     * @param  string  $absoluteImagePath  Absolute path to the receipt image on local disk.
     * @param  array<int, array{question: string, answer: string}>  $answered  Prior Q&A to feed back into the model.
     * @param  bool  $forceFinal  When true, the model must return a complete result (no more questions).
     */
    public function extract(string $absoluteImagePath, array $answered = [], bool $forceFinal = false): ExtractionResult;
}
