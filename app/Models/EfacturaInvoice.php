<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class EfacturaInvoice extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'efactura_invoices';

    protected $fillable = [
        'company_id',
        'cui',
        'invoice_id',
        'upload_index',
        'download_id',
        'message_type',
        'invoice_number',
        'invoice_date',
        'invoice_type',
        'supplier_name',
        'supplier_tax_id',
        'customer_name', 
        'customer_tax_id',
        'total_amount',
        'currency',
        'xml_content',
        'xml_signature',
        'xml_errors',
        'zip_content',
        'pdf_content',
        'status',
        'upload_status',
        'download_status',
        'anaf_response',
        'error_message',
        'validation_errors',
        'uploaded_at',
        'processed_at',
        'downloaded_at',
        'archived_at',
        'file_size',
        'original_filename',
        'checksum'
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'total_amount' => 'decimal:2',
            'uploaded_at' => 'datetime',
            'processed_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'archived_at' => 'datetime',
            'anaf_response' => 'array',
            'validation_errors' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function token()
    {
        return $this->belongsTo(EfacturaToken::class, 'cui', 'cui');
    }

    public function scopeForCui($query, string $cui)
    {
        return $query->where('cui', $cui);
    }

    public function scopeUploaded($query)
    {
        return $query->where('upload_status', 'uploaded');
    }

    public function scopePending($query)
    {
        return $query->where('upload_status', 'pending');
    }

    public function scopeWithErrors($query)
    {
        return $query->where('upload_status', 'error');
    }

    public function isUploaded(): bool
    {
        return $this->upload_status === 'uploaded';
    }

    public function hasErrors(): bool
    {
        return !empty($this->validation_errors) || !empty($this->error_message);
    }
}
