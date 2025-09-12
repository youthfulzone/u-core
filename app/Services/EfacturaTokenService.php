<?php

namespace App\Services;

use App\Models\EfacturaTokenHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Secure e-Factura token management service
 * 
 * Handles ANAF OAuth token lifecycle with security constraints:
 * - Prevents multiple simultaneous token generation
 * - Enforces 90-day refresh constraint
 * - Maintains comprehensive audit trail
 * - Provides secure token storage and retrieval
 */
class EfacturaTokenService
{
    private const LOCK_TIMEOUT = 300; // 5 minutes
    private const TOKEN_VALIDITY_DAYS = 90;
    private const REFRESH_TOKEN_VALIDITY_DAYS = 365;
    
    private ?string $clientId;
    private ?string $clientSecret;
    private ?string $redirectUri;

    public function __construct()
    {
        // Initialize with config values (nullable to handle missing env vars)
        $this->clientId = config('efactura.client_id');
        $this->clientSecret = config('efactura.client_secret');
        $this->redirectUri = config('efactura.redirect_uri');
    }

    /**
     * Generate new access token with security constraints
     * Prevents multiple simultaneous token generation
     */
    public function generateToken(string $authorizationCode): array
    {
        $this->validateConfiguration();
        
        $lockKey = 'efactura:token:generation:lock';
        
        // Prevent concurrent token generation
        if (Cache::has($lockKey)) {
            throw new \Exception('Token generation already in progress. Please wait and try again.');
        }

        // Check if we already have an active token that cannot be refreshed yet
        $activeToken = $this->getCurrentActiveToken();
        if ($activeToken && !$activeToken->canBeRefreshed()) {
            $daysRemaining = 90 - $activeToken->issued_at->diffInDays(now());
            throw new \Exception("Cannot generate new token. Current token is only {$activeToken->issued_at->diffInDays(now())} days old. Must wait {$daysRemaining} more days before refresh.");
        }

        Cache::put($lockKey, true, self::LOCK_TIMEOUT);

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post('https://logincert.anaf.ro/anaf-oauth2/v1/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $authorizationCode,
                    'redirect_uri' => $this->redirectUri,
                    'token_content_type' => 'jwt', // Required since 2024
                ]);

            if (!$response->successful()) {
                throw new \Exception('Failed to obtain access token: ' . $response->body());
            }

            $tokenData = $response->json();
            
            // Store token securely in history
            $tokenHistory = $this->storeTokenSecurely($tokenData);
            
            // Mark any previous active tokens as superseded (but keep for audit)
            $this->markPreviousTokensAsSuperseded();

            Log::info('New e-factura token generated successfully', [
                'token_id' => $tokenHistory->token_id,
                'expires_at' => $tokenHistory->expires_at,
                'client_id' => $this->clientId,
            ]);

            return [
                'success' => true,
                'token_id' => $tokenHistory->token_id,
                'expires_at' => $tokenHistory->expires_at,
                'days_until_expiry' => $tokenHistory->getDaysUntilExpiration(),
            ];

        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Refresh existing token with 90-day constraint
     */
    public function refreshToken(): array
    {
        $activeToken = $this->getCurrentActiveToken();
        
        if (!$activeToken) {
            throw new \Exception('No active token found to refresh.');
        }

        if (!$activeToken->canBeRefreshed()) {
            $daysRemaining = 90 - $activeToken->issued_at->diffInDays(now());
            throw new \Exception("Token cannot be refreshed yet. Must wait {$daysRemaining} more days (90-day minimum).");
        }

        $refreshToken = $activeToken->getDecryptedRefreshToken();
        if (!$refreshToken) {
            throw new \Exception('No refresh token available.');
        }

        $lockKey = 'efactura:token:refresh:lock';
        
        if (Cache::has($lockKey)) {
            throw new \Exception('Token refresh already in progress. Please wait and try again.');
        }

        Cache::put($lockKey, true, self::LOCK_TIMEOUT);

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post('https://logincert.anaf.ro/anaf-oauth2/v1/token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'token_content_type' => 'jwt',
                ]);

            if (!$response->successful()) {
                throw new \Exception('Failed to refresh token: ' . $response->body());
            }

            $tokenData = $response->json();
            
            // Store new token with parent reference
            $newTokenHistory = $this->storeTokenSecurely($tokenData, $activeToken->id);
            
            // Mark old token as refreshed (but keep for audit trail)
            $activeToken->update([
                'status' => 'refreshed',
                'revoked_reason' => 'refreshed',
                'revoked_at' => now(),
            ]);

            Log::info('e-factura token refreshed successfully', [
                'old_token_id' => $activeToken->token_id,
                'new_token_id' => $newTokenHistory->token_id,
                'expires_at' => $newTokenHistory->expires_at,
            ]);

            return [
                'success' => true,
                'token_id' => $newTokenHistory->token_id,
                'expires_at' => $newTokenHistory->expires_at,
                'days_until_expiry' => $newTokenHistory->getDaysUntilExpiration(),
            ];

        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Get current active token status for display
     */
    public function getTokenStatus(): array
    {
        $activeToken = $this->getCurrentActiveToken();
        
        if (!$activeToken) {
            return [
                'has_token' => false,
                'status' => 'no_token',
                'message' => 'No active token available.',
            ];
        }

        $daysUntilExpiry = $activeToken->getDaysUntilExpiration();
        $daysSinceIssued = $activeToken->issued_at->diffInDays(now());
        $canRefresh = $activeToken->canBeRefreshed();

        $status = 'active';
        $message = "Token active, expires in {$daysUntilExpiry} days.";

        if ($daysUntilExpiry <= 7) {
            $status = 'expiring_soon';
            $message = "⚠️ Token expires in {$daysUntilExpiry} days!";
        } elseif ($daysUntilExpiry <= 30) {
            $status = 'expiring_warning';
            $message = "Token expires in {$daysUntilExpiry} days.";
        }

        return [
            'has_token' => true,
            'status' => $status,
            'token_id' => $activeToken->token_id,
            'issued_at' => $activeToken->issued_at,
            'expires_at' => $activeToken->expires_at,
            'days_until_expiry' => $daysUntilExpiry,
            'days_since_issued' => $daysSinceIssued,
            'can_refresh' => $canRefresh,
            'days_until_refresh' => $canRefresh ? 0 : (90 - $daysSinceIssued),
            'usage_count' => $activeToken->usage_count,
            'last_used_at' => $activeToken->last_used_at,
            'message' => $message,
        ];
    }

    /**
     * Get security dashboard data
     */
    public function getSecurityDashboard(): array
    {
        return [
            'active_tokens' => EfacturaTokenHistory::getActiveTokens(),
            'expiring_tokens' => EfacturaTokenHistory::getExpiringTokens(30),
            'pending_revocations' => EfacturaTokenHistory::getPendingRevocations(),
            'total_tokens_issued' => EfacturaTokenHistory::count(),
            'compromised_count' => EfacturaTokenHistory::where('status', 'compromised')->count(),
        ];
    }

    /**
     * Mark token as compromised for ANAF manual revocation
     */
    public function markTokenAsCompromised(string $tokenId, string $reason): void
    {
        $token = EfacturaTokenHistory::where('token_id', $tokenId)->firstOrFail();
        
        $token->markAsCompromised($reason);
        
        // Generate ANAF support request ID
        $requestId = 'REV_' . strtoupper(uniqid()) . '_' . now()->format('Ymd');
        $token->requestAnafRevocation($requestId);
        
        // Send notification email or create support ticket
        // This would integrate with your support system
        Log::critical('e-factura token marked as compromised - ANAF manual revocation required', [
            'token_id' => $tokenId,
            'reason' => $reason,
            'anaf_request_id' => $requestId,
            'support_action_required' => true,
        ]);
    }

    /**
     * Get current active token (only one should exist)
     */
    private function getCurrentActiveToken(): ?EfacturaTokenHistory
    {
        return EfacturaTokenHistory::where('status', 'active')
            ->where('token_type', 'access')
            ->where('expires_at', '>', now())
            ->orderBy('issued_at', 'desc')
            ->first();
    }

    /**
     * Store token securely with full encryption and audit trail
     */
    private function storeTokenSecurely(array $tokenData, ?int $parentTokenId = null): EfacturaTokenHistory
    {
        $expiresAt = now()->addDays(self::TOKEN_VALIDITY_DAYS);
        
        $tokenHistory = new EfacturaTokenHistory([
            'token_type' => 'access',
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => $expiresAt,
            'client_id' => $this->clientId,
            'scopes' => $tokenData['scope'] ?? [],
            'issued_ip' => request()->ip(),
            'user_agent' => [
                'browser' => request()->userAgent(),
                'ip' => request()->ip(),
            ],
            'parent_token_id' => $parentTokenId,
        ]);

        $tokenHistory->save();
        
        // Store encrypted tokens
        $tokenHistory->storeAccessToken(
            $tokenData['access_token'],
            $tokenData['refresh_token'] ?? null
        );

        return $tokenHistory;
    }

    /**
     * Mark previous tokens as superseded (for audit trail)
     */
    private function markPreviousTokensAsSuperseded(): void
    {
        EfacturaTokenHistory::where('status', 'active')
            ->where('token_type', 'access')
            ->where('expires_at', '>', now())
            ->update([
                'status' => 'superseded',
                'revoked_reason' => 'new_token_generated',
                'revoked_at' => now(),
            ]);
    }

    /**
     * Clean up expired tokens (run via scheduled task)
     */
    public function cleanupExpiredTokens(): int
    {
        return EfacturaTokenHistory::markExpiredTokens();
    }

    /**
     * Get token for API usage (with usage tracking)
     */
    public function getTokenForApiCall(): ?string
    {
        $activeToken = $this->getCurrentActiveToken();
        
        if (!$activeToken || !$activeToken->isValid()) {
            return null;
        }

        return $activeToken->getDecryptedToken();
    }

    /**
     * Validate that required configuration is present
     */
    private function validateConfiguration(): void
    {
        if (!$this->clientId || !$this->clientSecret || !$this->redirectUri) {
            throw new \Exception('E-factura credentials not configured. Please set EFACTURA_CLIENT_ID, EFACTURA_CLIENT_SECRET, and EFACTURA_REDIRECT_URI in your environment file.');
        }
    }
}