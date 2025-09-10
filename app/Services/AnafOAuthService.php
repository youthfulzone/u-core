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
    private const ANAF_OAUTH_BASE_URL = 'https://logincert.anaf.ro/anaf-oauth2';
    private const ANAF_TEST_BASE_URL = 'https://logincert-test.anaf.ro/anaf-oauth2';

    public function __construct(
        private string $environment = 'sandbox'
    ) {}

    public function getAuthorizationUrl(string $clientId, string $redirectUri, string $state = null): string
    {
        $baseUrl = $this->environment === 'production' 
            ? self::ANAF_OAUTH_BASE_URL 
            : self::ANAF_TEST_BASE_URL;

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'efactura',
            'state' => $state ?? bin2hex(random_bytes(16))
        ]);

        return "{$baseUrl}/authorize?{$params}";
    }

    public function exchangeCodeForToken(string $code, string $clientId, string $clientSecret, string $redirectUri): array
    {
        $baseUrl = $this->environment === 'production' 
            ? self::ANAF_OAUTH_BASE_URL 
            : self::ANAF_TEST_BASE_URL;

        $response = Http::asForm()->post("{$baseUrl}/token", [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri
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
        $baseUrl = $this->environment === 'production' 
            ? self::ANAF_OAUTH_BASE_URL 
            : self::ANAF_TEST_BASE_URL;

        $response = Http::asForm()->post("{$baseUrl}/token", [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
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

    public function storeToken(string $cui, array $tokenData, string $clientId, string $clientSecret): EfacturaToken
    {
        $company = Company::where('cui', $cui)->first();

        return EfacturaToken::updateOrCreate(
            ['cui' => $cui, 'client_id' => $clientId],
            [
                'company_id' => $company?->_id,
                'client_secret' => $clientSecret,
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
                'expires_at' => isset($tokenData['expires_in']) 
                    ? Carbon::now()->addSeconds($tokenData['expires_in'])
                    : null,
                'scope' => $tokenData['scope'] ?? 'efactura',
                'status' => 'active',
                'created_via' => 'oauth_flow',
                'last_used_at' => Carbon::now()
            ]
        );
    }

    public function getValidToken(string $cui): ?EfacturaToken
    {
        $token = EfacturaToken::forCui($cui)->active()->first();

        if (!$token) {
            return null;
        }

        if ($token->isExpired() && $token->refresh_token) {
            try {
                $newTokenData = $this->refreshToken(
                    $token->refresh_token,
                    $token->client_id,
                    $token->client_secret
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
                Log::error('Failed to refresh token for CUI: ' . $cui, [
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

    public function revokeToken(string $cui): bool
    {
        $token = EfacturaToken::forCui($cui)->active()->first();

        if (!$token) {
            return true; // Already revoked/doesn't exist
        }

        $baseUrl = $this->environment === 'production' 
            ? self::ANAF_OAUTH_BASE_URL 
            : self::ANAF_TEST_BASE_URL;

        try {
            Http::asForm()->post("{$baseUrl}/revoke", [
                'token' => $token->access_token,
                'client_id' => $token->client_id,
                'client_secret' => $token->client_secret
            ]);

            $token->update([
                'status' => 'revoked',
                'error_message' => null
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to revoke token for CUI: ' . $cui, [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}
