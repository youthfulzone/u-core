<?php

namespace App\Services;

use App\Models\AnafCredential;
use App\Models\EfacturaToken;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnafOAuthService
{
    private const ANAF_AUTH_URL = 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize';
    private const ANAF_TOKEN_URL = 'https://logincert.anaf.ro/anaf-oauth2/v1/token';
    
    // Sandbox URLs (if needed)
    private const ANAF_SANDBOX_AUTH_URL = 'https://oauth-test.anaf.ro/anaf-oauth2/v1/authorize';
    private const ANAF_SANDBOX_TOKEN_URL = 'https://oauth-test.anaf.ro/anaf-oauth2/v1/token';

    public function __construct(
        private string $environment = 'sandbox'
    ) {}

    public function getAuthorizationUrl(string $clientId, string $redirectUri): string
    {
        // Generate CSRF protection state
        $state = bin2hex(random_bytes(16));
        
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'EFACTURA',  // Required scope for e-Factura access
            'state' => $state,  // CSRF protection
            'token_content_type' => 'jwt'  // ANAF requires this parameter
        ]);

        $authUrl = $this->environment === 'sandbox' ? self::ANAF_SANDBOX_AUTH_URL : self::ANAF_AUTH_URL;
        
        // Store state in session for verification
        session(['oauth_state' => $state]);
        
        return $authUrl . "?{$params}";
    }

    public function exchangeCodeForToken(string $code, string $clientId, string $clientSecret, string $redirectUri): array
    {
        $tokenUrl = $this->environment === 'sandbox' ? self::ANAF_SANDBOX_TOKEN_URL : self::ANAF_TOKEN_URL;
        
        // Use Basic Auth header as per official ANAF documentation
        $response = Http::withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post($tokenUrl, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'token_content_type' => 'jwt'
            ]);

        if (!$response->successful()) {
            Log::error('ANAF OAuth token exchange failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            throw new \Exception('Failed to exchange authorization code for token: ' . $response->body());
        }

        return $response->json();
    }

    public function refreshToken(string $refreshToken, string $clientId, string $clientSecret): array
    {
        $response = Http::withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post(self::ANAF_TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'token_content_type' => 'jwt'
            ]);

        if (!$response->successful()) {
            Log::error('ANAF OAuth token refresh failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            throw new \Exception('Failed to refresh token: ' . $response->body());
        }

        return $response->json();
    }

    public function storeToken(array $tokenData, string $clientId): EfacturaToken
    {
        // Deactivate any existing tokens for this client_id to ensure only one active token
        EfacturaToken::where('client_id', $clientId)
            ->where('status', 'active')
            ->update(['status' => 'replaced']);

        return EfacturaToken::create([
            'client_id' => $clientId,
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'expires_at' => isset($tokenData['expires_in']) 
                ? Carbon::now()->addSeconds($tokenData['expires_in'])
                : null,
            'scope' => $tokenData['scope'] ?? '',
            'status' => 'active',
            'created_via' => 'oauth_flow',
            'last_used_at' => Carbon::now()
        ]);
    }

    public function getValidToken(): ?EfacturaToken
    {
        // Get the active credential and its token
        $credential = AnafCredential::active()->first();
        
        if (!$credential) {
            return null;
        }

        $token = EfacturaToken::forClientId($credential->client_id)->active()->first();

        if (!$token) {
            return null;
        }

        if ($token->isExpired() && $token->refresh_token) {
            try {
                $newTokenData = $this->refreshToken(
                    $token->refresh_token,
                    $token->client_id,
                    $credential->client_secret
                );

                $token->update([
                    'access_token' => $newTokenData['access_token'],
                    'refresh_token' => $newTokenData['refresh_token'] ?? $token->refresh_token,
                    'expires_at' => isset($newTokenData['expires_in']) 
                        ? Carbon::now()->addSeconds($newTokenData['expires_in'])
                        : null,
                    'last_used_at' => Carbon::now()
                ]);

                return $token->fresh();
            } catch (\Exception $e) {
                Log::error('Failed to refresh token', [
                    'client_id' => $token->client_id,
                    'error' => $e->getMessage()
                ]);

                $token->update([
                    'status' => 'expired',
                    'error_message' => $e->getMessage()
                ]);

                return null;
            }
        }

        if ($token->isValid()) {
            $token->update(['last_used_at' => Carbon::now()]);
            return $token;
        }

        return null;
    }

    public function revokeToken(): bool
    {
        $credential = AnafCredential::active()->first();
        
        if (!$credential) {
            return true;
        }

        $token = EfacturaToken::forClientId($credential->client_id)->active()->first();

        if (!$token) {
            return true; // Already revoked/doesn't exist
        }

        try {
            // Note: ANAF may not have a revoke endpoint, but we'll mark as revoked locally
            $token->update([
                'status' => 'revoked',
                'error_message' => null
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to revoke token', [
                'client_id' => $credential->client_id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}
