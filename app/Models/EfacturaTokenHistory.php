<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Carbon\Carbon;

/**
 * Secure token history model for ANAF e-Factura OAuth tokens
 * 
 * Handles the critical requirement to track all tokens due to ANAF's inability
 * to revoke JWT tokens through automated means. Provides comprehensive audit
 * trail and security tracking for manual ANAF revocation processes.
 */
class EfacturaTokenHistory extends Model
{
    protected $fillable = [
        'token_id',
        'token_type', 
        'status',
        'encrypted_token',
        'encrypted_refresh_token',
        'issued_at',
        'expires_at',
        'last_used_at',
        'usage_count',
        'client_id',
        'scopes',
        'issued_ip',
        'last_used_ip',
        'user_agent',
        'revoked_at',
        'revoked_reason',
        'revoked_by',
        'security_notes',
        'anaf_revocation_request_id',
        'anaf_revocation_requested_at', 
        'anaf_revocation_status',
        'parent_token_id',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime', 
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'anaf_revocation_requested_at' => 'datetime',
        'scopes' => 'array',
        'user_agent' => 'array',
        'usage_count' => 'integer',
    ];

    protected $hidden = [
        'encrypted_token',
        'encrypted_refresh_token',
    ];

    /**
     * Boot the model to set default values
     */
    protected static function boot(): void
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (!$model->token_id) {
                $model->token_id = self::generateTokenId();
            }
        });
    }

    /**
     * Parent token relationship (for refresh chains)
     */
    public function parentToken(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_token_id');
    }

    /**
     * Child tokens relationship (tokens created from this refresh token)
     */
    public function childTokens(): HasMany
    {
        return $this->hasMany(self::class, 'parent_token_id');
    }

    /**
     * Securely store an access token with encryption
     */
    public function storeAccessToken(string $accessToken, ?string $refreshToken = null): void
    {
        $this->encrypted_token = Crypt::encryptString($accessToken);
        
        if ($refreshToken) {
            $this->encrypted_refresh_token = Crypt::encryptString($refreshToken);
        }
        
        $this->save();
    }

    /**
     * Securely retrieve decrypted access token
     * WARNING: Only use when absolutely necessary for API calls
     */
    public function getDecryptedToken(): ?string
    {
        if (!$this->encrypted_token) {
            return null;
        }

        try {
            $token = Crypt::decryptString($this->encrypted_token);
            
            // Update usage tracking
            $this->increment('usage_count');
            $this->update([
                'last_used_at' => now(),
                'last_used_ip' => request()->ip(),
            ]);
            
            return $token;
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt e-factura token', [
                'token_id' => $this->token_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Securely retrieve decrypted refresh token
     */
    public function getDecryptedRefreshToken(): ?string
    {
        if (!$this->encrypted_refresh_token) {
            return null;
        }

        try {
            return Crypt::decryptString($this->encrypted_refresh_token);
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt e-factura refresh token', [
                'token_id' => $this->token_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if token is currently valid and not expired
     */
    public function isValid(): bool
    {
        return $this->status === 'active' 
            && $this->expires_at > now()
            && !$this->isRevoked();
    }

    /**
     * Check if token has been revoked
     */
    public function isRevoked(): bool
    {
        return in_array($this->status, ['revoked', 'compromised']) || $this->revoked_at !== null;
    }

    /**
     * Check if token is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at <= now();
    }

    /**
     * Get days until expiration
     */
    public function getDaysUntilExpiration(): int
    {
        return max(0, now()->diffInDays($this->expires_at, false));
    }

    /**
     * Check if token can be refreshed (only after 90 days as per requirements)
     */
    public function canBeRefreshed(): bool
    {
        if ($this->token_type !== 'access') {
            return false;
        }

        // ANAF tokens can only be refreshed after 90 days
        $daysSinceIssued = $this->issued_at->diffInDays(now());
        return $daysSinceIssued >= 90 && $this->isValid();
    }

    /**
     * Mark token as compromised and prepare for ANAF manual revocation
     */
    public function markAsCompromised(string $reason, ?string $revokedBy = null): void
    {
        $this->update([
            'status' => 'compromised',
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            'revoked_by' => $revokedBy ?? auth()->user()?->name,
            'anaf_revocation_status' => 'pending',
        ]);

        // Log security incident
        \Log::critical('e-Factura token marked as compromised', [
            'token_id' => $this->token_id,
            'reason' => $reason,
            'revoked_by' => $revokedBy,
            'expires_at' => $this->expires_at,
            'days_until_expiry' => $this->getDaysUntilExpiration(),
        ]);
    }

    /**
     * Request manual revocation from ANAF
     */
    public function requestAnafRevocation(string $requestId): void
    {
        $this->update([
            'anaf_revocation_request_id' => $requestId,
            'anaf_revocation_requested_at' => now(),
            'anaf_revocation_status' => 'pending',
        ]);
    }

    /**
     * Update ANAF revocation status
     */
    public function updateAnafRevocationStatus(string $status): void
    {
        $this->update([
            'anaf_revocation_status' => $status,
        ]);

        if ($status === 'completed') {
            $this->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_reason' => 'anaf_manual',
            ]);
        }
    }

    /**
     * Generate a unique token ID for identification without exposing the token
     */
    protected static function generateTokenId(): string
    {
        return 'eft_' . hash('sha256', uniqid(mt_rand(), true) . microtime());
    }

    /**
     * Get active tokens that are about to expire (for notification)
     */
    public static function getExpiringTokens(int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($days))
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Get all active tokens for security audit
     */
    public static function getActiveTokens(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('status', 'active')
            ->where('expires_at', '>', now())
            ->orderBy('issued_at', 'desc')
            ->get();
    }

    /**
     * Get compromised tokens pending ANAF revocation
     */
    public static function getPendingRevocations(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('status', 'compromised')
            ->where('anaf_revocation_status', 'pending')
            ->orderBy('revoked_at')
            ->get();
    }

    /**
     * Clean up expired tokens (keep for audit purposes but mark as expired)
     */
    public static function markExpiredTokens(): int
    {
        return static::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
            ]);
    }
}