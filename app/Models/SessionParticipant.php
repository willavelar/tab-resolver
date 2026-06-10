<?php

namespace App\Models;

use Database\Factories\SessionParticipantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionParticipant extends Model
{
    /** @use HasFactory<SessionParticipantFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'bill_session_id',
        'name',
        'submitter_token',
        'text',
        'audio_path',
        'audio_duration',
        'ip_address',
        'user_agent',
        'transcript',
        'amount_due',
        'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'audio_duration' => 'integer',
            'amount_due' => 'decimal:2',
            'breakdown' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'bill_session_id');
    }
}
