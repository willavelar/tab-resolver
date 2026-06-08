<?php

namespace App\Services\Receipt;

interface ReceiptExtractor
{
    /**
     * Read a receipt image and return its structured line items and totals.
     *
     * @param  string  $absoluteImagePath  Absolute path to the receipt image on local disk.
     */
    public function extract(string $absoluteImagePath): ExtractionResult;
}
