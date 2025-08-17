<?php

namespace App\Models\Spv;

use MongoDB\Laravel\Eloquent\Model;

class SpvRequest extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'spv_requests';

    protected $fillable = [
        'user_id',
        'anaf_id_solicitare',
        'tip',
        'cui',
        'an',
        'luna',
        'motiv',
        'numar_inregistrare',
        'cui_pui',
        'status',
        'parametri',
        'cnp',
        'serial',
        'response_data',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'parametri' => 'array',
            'response_data' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_ERROR = 'error';

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function hasError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function markAsProcessed(array $responseData): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSED,
            'response_data' => $responseData,
            'processed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsError(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'error_message' => $errorMessage,
            'processed_at' => now(),
        ]);
    }

    public function getFormattedProcessedAtAttribute(): string
    {
        return $this->processed_at ? $this->processed_at->format('d.m.Y H:i:s') : '';
    }

    public function scopeForUser($query, int|string $userId)
    {
        return $query->where('user_id', (string) $userId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('tip', $type);
    }

    public function scopeForCui($query, string $cui)
    {
        return $query->where('cui', $cui);
    }
}