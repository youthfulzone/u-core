<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Carbon\Carbon;

class EfacturaToken extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'efactura_tokens';

    protected $fillable = [
        'client_id',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_at',
        'scope',
        'status',
        'error_message',
        'last_used_at',
        'created_via'
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function credential()
    {
        return $this->belongsTo(AnafCredential::class, 'client_id', 'client_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && Carbon::now()->isAfter($this->expires_at);
    }

    public function isValid(): bool
    {
        return $this->status === 'active' && !$this->isExpired() && !empty($this->access_token);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForClientId($query, string $clientId)
    {
        return $query->where('client_id', $clientId);
    }
}
