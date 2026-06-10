<?php

namespace App\Enums;

enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case NeedsClarification = 'needs_clarification';
    case Failed = 'failed';
}
