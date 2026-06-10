<?php

namespace App\Services\Bill;

class SplitResult
{
    /**
     * @param  array<int, array<string, mixed>>  $allocations
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly array $allocations = [],
        public readonly array $questions = [],
        public readonly array $raw = [],
    ) {}

    public function needsInput(): bool
    {
        return $this->status === 'needs_input';
    }

    /**
     * @param  array<int, array<string, mixed>>  $allocations
     * @param  array<string, mixed>  $raw
     */
    public static function complete(array $allocations, array $raw = []): self
    {
        return new self(status: 'complete', allocations: $allocations, raw: $raw);
    }

    /**
     * @param  array<int, array{id: string, prompt: string, type: string, options: array<int, string>}>  $questions
     * @param  array<string, mixed>  $raw
     */
    public static function requestInput(array $questions, array $raw = []): self
    {
        return new self(status: 'needs_input', questions: $questions, raw: $raw);
    }
}
