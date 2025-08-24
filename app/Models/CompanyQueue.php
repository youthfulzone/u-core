<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model as Eloquent;

class CompanyQueue extends Eloquent
{
    protected $connection = 'mongodb';

    protected $collection = 'company_queues';

    protected $fillable = [
        'cui',
        'status',
        'anaf_data',
        'processed_at',
        'processed_by',
    ];

    protected $casts = [
        'anaf_data' => 'array',
        'processed_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }
}
