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
        'cif',
        'document_type',
        'company_name',
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
        'response_received_at',
    ];

    protected function casts(): array
    {
        return [
            'parametri' => 'array',
            'response_data' => 'array',
            'processed_at' => 'datetime',
            'response_received_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RESPONSE_RECEIVED = 'response_received';

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function relatedMessages()
    {
        if (! $this->response_data || ! isset($this->response_data['id_solicitare'])) {
            return SpvMessage::whereRaw('false'); // Return empty query
        }

        return SpvMessage::where('id_solicitare', $this->response_data['id_solicitare']);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasResponseReceived(): bool
    {
        return $this->status === self::STATUS_RESPONSE_RECEIVED;
    }

    public function hasResponse(): bool
    {
        return $this->relatedMessages()->exists();
    }

    public function markAsCompleted(array $responseData): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'response_data' => $responseData,
            'processed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsResponseReceived(): void
    {
        $this->update([
            'status' => self::STATUS_RESPONSE_RECEIVED,
            'response_received_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
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
