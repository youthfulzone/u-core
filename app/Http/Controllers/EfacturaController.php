<?php

namespace App\Http\Controllers;

use App\Models\AnafCredential;
use App\Models\EfacturaToken;
use App\Services\AnafOAuthService;
use App\Services\EfacturaApiService;
use App\Services\CloudflaredService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class EfacturaController extends Controller
{
    public function __construct(
        private AnafOAuthService $oauthService,
        private EfacturaApiService $apiService,
        private CloudflaredService $cloudflaredService
    ) {}

    public function index()
    {
        $credential = AnafCredential::active()->first();
        $token = $credential ? EfacturaToken::forClientId($credential->client_id)->active()->first() : null;

        return Inertia::render('Efactura/Index', [
            'hasCredentials' => (bool) $credential,
            'hasValidToken' => $token && $token->isValid(),
            'tokenExpiresAt' => $token?->expires_at?->toISOString(),
            'cloudflaredStatus' => [
                'running' => null, // Will be loaded via AJAX
                'tunnel_url' => 'https://efactura.scyte.ro',
                'callback_url' => 'https://efactura.scyte.ro/efactura/oauth/callback',
                'message' => 'Loading...',
                'required' => false
            ]
        ]);
    }

    public function authenticate()
    {
        // Ensure cloudflared is running before OAuth
        $this->cloudflaredService->ensureRunning();
        
        $credential = AnafCredential::active()->first();

        if (!$credential) {
            return response()->json(['error' => 'No active ANAF credentials found'], 400);
        }

        $authUrl = $this->oauthService->getAuthorizationUrl(
            $credential->client_id,
            $credential->redirect_uri
        );

        return response()->json(['auth_url' => $authUrl]);
    }

    public function callback(Request $request)
    {
        $code = $request->get('code');
        $error = $request->get('error');

        if ($error) {
            Log::error('OAuth callback error', ['error' => $error]);
            return redirect()->route('efactura.index')->with('error', 'Authentication failed: ' . $error);
        }

        if (!$code) {
            return redirect()->route('efactura.index')->with('error', 'No authorization code received');
        }

        try {
            $credential = AnafCredential::active()->first();

            if (!$credential) {
                return redirect()->route('efactura.index')->with('error', 'No active credentials found');
            }

            // Exchange code for tokens
            $tokenData = $this->oauthService->exchangeCodeForToken(
                $code,
                $credential->client_id,
                $credential->client_secret,
                $credential->redirect_uri
            );

            // Store the tokens (this will replace any existing active token)
            $this->oauthService->storeToken($tokenData, $credential->client_id);

            return redirect()->route('efactura.index')->with('success', 'Successfully authenticated with ANAF');

        } catch (\Exception $e) {
            Log::error('OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect()->route('efactura.index')->with('error', 'Authentication failed: ' . $e->getMessage());
        }
    }

    public function status()
    {
        // Start tunnel check in background if not running (non-blocking)
        if (!$this->cloudflaredService->isRunning()) {
            // Start tunnel asynchronously without blocking
            $this->startTunnelAsync();
        }
        
        $credential = AnafCredential::active()->first();
        $token = $credential ? EfacturaToken::forClientId($credential->client_id)->active()->first() : null;

        return response()->json([
            'hasCredentials' => (bool) $credential,
            'hasValidToken' => $token && $token->isValid(),
            'tokenExpiresAt' => $token?->expires_at?->toISOString(),
            'cloudflaredStatus' => $this->cloudflaredService->getStatus()
        ]);
    }

    private function startTunnelAsync(): void
    {
        // Start tunnel in background without blocking the response
        if (file_exists(base_path('cloudflared/e.py'))) {
            $command = "cd /d \"" . base_path('cloudflared') . "\" && start /B python e.py";
            shell_exec($command . ' > NUL 2>&1 &');
        }
    }

    public function revoke()
    {
        try {
            $this->oauthService->revokeToken();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Token revocation failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

}
