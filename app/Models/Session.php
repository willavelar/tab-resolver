<?php

namespace App\Models;

use App\Enums\ExtractionStatus;
use Database\Factories\SessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    /** @use HasFactory<SessionFactory> */
    use HasFactory, HasUlids;

    protected $table = 'bill_sessions';

    protected $fillable = [
        'title',
        'image_path',
        'status',
        'subtotal',
        'service_charge',
        'total',
        'raw_extraction',
        'processed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtractionStatus::class,
            'subtotal' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'total' => 'decimal:2',
            'raw_extraction' => 'array',
            'processed_at' => 'datetime',
        ];
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
}
