<?php

namespace App\Http\Controllers;

use App\Models\AnafCredential;
use App\Models\EfacturaToken;
use App\Services\AnafOAuthService;
use App\Services\EfacturaApiService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;

class EfacturaController extends Controller
{
    public function __construct(
        private AnafOAuthService $oauthService,
        private EfacturaApiService $apiService
    ) {}

    public function index()
    {
        $credential = AnafCredential::active()->first();
        $token = $credential ? EfacturaToken::forClientId($credential->client_id)->active()->first() : null;

        return Inertia::render('Efactura/Index', [
            'hasCredentials' => (bool) $credential,
            'hasValidToken' => $token && $token->isValid(),
            'tokenExpiresAt' => $token?->expires_at?->toISOString(),
            'cloudflaredStatus' => $this->getCloudflaredStatus()
        ]);
    }

    public function authenticate()
    {
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
        $credential = AnafCredential::active()->first();
        $token = $credential ? EfacturaToken::forClientId($credential->client_id)->active()->first() : null;

        return response()->json([
            'hasCredentials' => (bool) $credential,
            'hasValidToken' => $token && $token->isValid(),
            'tokenExpiresAt' => $token?->expires_at?->toISOString(),
            'cloudflaredStatus' => $this->getCloudflaredStatus()
        ]);
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

    private function getCloudflaredStatus(): array
    {
        $status = [
            'running' => false,
            'tunnel_url' => null,
            'callback_url' => null,
            'message' => 'Cloudflared tunnel not detected',
            'required' => true,
            'setup_command' => null
        ];

        // Check if cloudflared.exe exists
        $cloudflaredPath = base_path('cloudflared/cloudflared.exe');
        if (!file_exists($cloudflaredPath)) {
            $status['message'] = 'Cloudflared executable not found';
            return $status;
        }

        // Check if cloudflared process is running (Windows)
        $output = shell_exec('tasklist /FI "IMAGENAME eq cloudflared.exe" 2>NUL');
        
        if ($output && strpos($output, 'cloudflared.exe') !== false) {
            $status['running'] = true;
            $status['tunnel_url'] = 'https://efactura.scyte.ro';
            $status['callback_url'] = 'https://efactura.scyte.ro/efactura/oauth/callback';
            $status['message'] = 'Tunnel active - OAuth ready';
            
            // Test if the tunnel is actually accessible
            try {
                $response = @file_get_contents('https://efactura.scyte.ro', false, stream_context_create([
                    'http' => ['timeout' => 3]
                ]));
                
                if ($response === false) {
                    $status['message'] = 'Tunnel process running but not accessible';
                    $status['running'] = false;
                }
            } catch (\Exception $e) {
                $status['message'] = 'Tunnel process running but not accessible';
                $status['running'] = false;
            }
        } else {
            $status['message'] = 'Tunnel not running - OAuth will fail';
            $status['setup_command'] = 'cd cloudflared && python e.py';
        }

        return $status;
    }
}
