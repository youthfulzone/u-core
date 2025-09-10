<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AnafCredential extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'anaf_credentials';

    protected $fillable = [
        'environment',
        'client_id',
        'client_secret',
        'redirect_uri',
        'scope',
        'is_active',
        'description',
        'valid_until',
        'created_by'
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'valid_until' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'client_secret',
    ];

    public function tokens()
    {
        return $this->hasMany(EfacturaToken::class, 'client_id', 'client_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEnvironment($query, string $environment)
    {
        return $query->where('environment', $environment);
    }

    public function isValid(): bool
    {
        return $this->is_active && 
               (!$this->valid_until || now()->isBefore($this->valid_until));
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }
}
