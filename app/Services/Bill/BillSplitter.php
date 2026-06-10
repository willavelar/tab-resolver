<?php

namespace App\Services\Bill;

use App\Models\Session;

interface BillSplitter
{
    /**
     * Compute per-participant amounts, or return clarification questions.
     *
     * @param  array<int, array{id: string, name: string}>  $participants
     * @param  array<int, array{question: string, answer: string}>  $answered  Prior Q&A fed back to the model.
     * @param  bool  $forceFinal  When true, must return complete allocations (no more questions).
     */
    public function split(Session $session, array $participants, bool $foodShared, array $answered = [], bool $forceFinal = false): SplitResult;
}
