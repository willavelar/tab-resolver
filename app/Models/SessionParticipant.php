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
        'text',
        'audio_path',
        'audio_duration',
    ];

    protected function casts(): array
    {
        return [
            'audio_duration' => 'integer',
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
