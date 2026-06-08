<?php

namespace App\Models;

use App\Enums\ExtractionStatus;
use Database\Factories\SessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Session extends Model
{
    /** @use HasFactory<SessionFactory> */
    use HasFactory, HasUlids;

    protected $table = 'bill_sessions';

    protected $fillable = [
        'title',
        'image_path',
        'public_token',
        'status',
        'subtotal',
        'service_charge',
        'service_charge_percentage',
        'total',
        'raw_extraction',
        'clarifications',
        'processed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtractionStatus::class,
            'subtotal' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'service_charge_percentage' => 'decimal:2',
            'total' => 'decimal:2',
            'raw_extraction' => 'array',
            'clarifications' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Session $session): void {
            if (empty($session->public_token)) {
                $session->public_token = Str::random(32);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SessionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SessionItem::class, 'bill_session_id')->orderBy('position');
    }

    /**
     * @return HasMany<SessionParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(SessionParticipant::class, 'bill_session_id')
            ->orderBy('created_at');
    }
}
