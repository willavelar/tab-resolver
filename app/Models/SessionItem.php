<?php

namespace App\Models;

use Database\Factories\SessionItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionItem extends Model
{
    /** @use HasFactory<SessionItemFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'bill_session_id',
        'name',
        'quantity',
        'unit_price',
        'total_price',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'position' => 'integer',
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
