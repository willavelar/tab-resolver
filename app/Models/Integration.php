<?php

namespace App\Models;

use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'provider',
        'api_key',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }

    /**
     * Singleton global da integração (um registro por provider).
     */
    public static function current(string $provider = 'anthropic'): self
    {
        return static::firstOrNew(['provider' => $provider]);
    }
}
