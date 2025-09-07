<?php

namespace App\Models\Spv;

use MongoDB\Laravel\Eloquent\Model;
use Carbon\Carbon;

class SpvMessage extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'spv_messages';

    protected $fillable = [
        'anaf_id',
        'detalii',
        'cif',
        'data_creare',
        'id_solicitare',
        'tip',
        'user_id',
        'cnp',
        'cui_list',
        'serial',
        'downloaded_at',
        'downloaded_by',
        'file_path',
        'file_size',
        'file_content',
        'file_name',
        'content_type',
        'original_data',
    ];

    protected function casts(): array
    {
        return [
            'data_creare' => 'datetime',
            'downloaded_at' => 'datetime',
            'cui_list' => 'array',
            'original_data' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function isDownloaded(): bool
    {
        return !is_null($this->downloaded_at);
    }

    public function getFormattedDateCreareAttribute(): string
    {
        return $this->data_creare ? $this->data_creare->format('d.m.Y H:i:s') : '';
    }

    public function getFormattedDownloadedAtAttribute(): string
    {
        return $this->downloaded_at ? $this->downloaded_at->format('d.m.Y H:i:s') : '';
    }

    public function markAsDownloaded(int|string $userId, ?string $filePath = null, ?int $fileSize = null): void
    {
        $this->update([
            'downloaded_at' => now(),
            'downloaded_by' => (string) $userId,
            'file_path' => $filePath,
            'file_size' => $fileSize,
        ]);
    }

    /**
     * Store file content directly in MongoDB (atomic operation)
     */
    public function storeFileInDatabase(string $fileContent, int|string $userId, string $contentType = 'application/pdf'): void
    {
        // Generate intelligent filename
        $fileName = \App\Services\SpvFileNameService::generateFullFileName($this->detalii, $this->cif);
        
        $this->update([
            'downloaded_at' => now(),
            'downloaded_by' => (string) $userId,
            'file_content' => base64_encode($fileContent), // Store as base64 for MongoDB compatibility
            'file_name' => $fileName,
            'file_size' => strlen($fileContent),
            'content_type' => $contentType,
            'file_path' => null, // Clear old file path since we're storing in DB
        ]);
    }

    /**
     * Get file content from database
     */
    public function getFileContent(): ?string
    {
        if (empty($this->file_content)) {
            return null;
        }
        
        return base64_decode($this->file_content);
    }

    /**
     * Check if file is stored in database
     */
    public function hasFileInDatabase(): bool
    {
        return !empty($this->file_content);
    }

    /**
     * Get filename with proper extension
     */
    public function getFileName(): string
    {
        return $this->file_name ?: \App\Services\SpvFileNameService::generateFullFileName($this->detalii, $this->cif);
    }

    public function scopeForUser($query, int|string $userId)
    {
        return $query->where('user_id', (string) $userId);
    }

    public function scopeForCif($query, string $cif)
    {
        return $query->where('cif', $cif);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('tip', $type);
    }

    public function scopeRecent($query, int $days = 60)
    {
        return $query->where('data_creare', '>=', now()->subDays($days));
    }

    public function scopeNotDownloaded($query)
    {
        return $query->whereNull('downloaded_at');
    }

    /**
     * Accessor for has_file_in_database attribute
     */
    public function getHasFileInDatabaseAttribute(): bool
    {
        return $this->hasFileInDatabase();
    }
}