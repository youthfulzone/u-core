<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ApiCallTracker extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'api_call_trackers';

    protected $fillable = [
        'api_name',
        'calls_made',
        'calls_limit',
        'errors',
        'reset_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'reset_at' => 'datetime',
        ];
    }

    public function getRemainingCallsAttribute(): int
    {
        return max(0, $this->calls_limit - $this->calls_made);
    }

    public function incrementCalls(): void
    {
        $this->increment('calls_made');
    }

    public function addError(string $error): void
    {
        $errors = $this->errors ?? [];
        $errors[] = [
            'error' => $error,
            'timestamp' => now()->toIso8601String(),
            'call_number' => $this->calls_made,
        ];
        $this->errors = $errors;
        $this->save();
    }

    public function resetCounter(): void
    {
        $this->calls_made = 0;
        $this->errors = [];
        $this->reset_at = now()->addHours(24);
        $this->save();
    }
}
