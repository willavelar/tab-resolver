<?php

namespace App\Models;

use Database\Factories\SessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    /** @use HasFactory<SessionFactory> */
    use HasFactory, HasUlids;

    protected $table = 'bill_sessions';

    protected $fillable = [
        'title',
        'image_path',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
