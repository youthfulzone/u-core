<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEfacturaSync;
use App\Jobs\ProcessLiveEfacturaSync;
use App\Models\AnafCredential;
use App\Models\Company;
use App\Models\EfacturaToken;
use App\Models\EfacturaInvoice;
use App\Services\AnafOAuthService;
use App\Services\EfacturaApiService;
use App\Services\AnafEfacturaService;
use App\Services\CloudflaredService;
use App\Services\EfacturaTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class EfacturaController extends Controller
{
    public function __construct(
        private AnafOAuthService $oauthService,
        private EfacturaApiService $apiService,
        private AnafEfacturaService $efacturaService,
        private CloudflaredService $cloudflaredService,
        private EfacturaTokenService $tokenService
    ) {}

    public function index()
    {
        Log::info('E-factura index page accessed', [
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
        
        $credential = AnafCredential::active()->first();
        $token = $credential ? EfacturaToken::forClientId($credential->client_id)->active()->first() : null;
        
        // Build token status from existing token system
        if ($token && $token->isValid()) {
            $daysUntilExpiry = max(0, now()->diffInDays($token->expires_at, false));
            $daysSinceIssued = $token->created_at->diffInDays(now());
            $canRefresh = $daysSinceIssued >= 90; // 90-day constraint
            
            $status = 'active';
            $message = "Token active, expires in {$daysUntilExpiry} days.";
            
            if ($daysUntilExpiry <= 7) {
                $status = 'expiring_soon';
                $message = "⚠️ Token expires in {$daysUntilExpiry} days!";
            } elseif ($daysUntilExpiry <= 30) {
                $status = 'expiring_warning';
                $message = "Token expires in {$daysUntilExpiry} days.";
            }
            
            $tokenStatus = [
                'has_token' => true,
                'status' => $status,
                'token_id' => 'eft_' . substr(md5($token->id), 0, 8),
                'issued_at' => $token->created_at->toISOString(),
                'expires_at' => $token->expires_at->toISOString(),
                'days_until_expiry' => $daysUntilExpiry,
                'days_since_issued' => $daysSinceIssued,
                'can_refresh' => $canRefresh,
                'days_until_refresh' => $canRefresh ? 0 : (90 - $daysSinceIssued),
                'usage_count' => 0, // Not tracked in old system
                'last_used_at' => $token->updated_at->toISOString(),
                'message' => $message,
            ];
        } else {
            $tokenStatus = [
                'has_token' => false,
                'status' => 'no_token',
                'message' => 'No active token available.'
            ];
        }
        
        // Build security dashboard from existing data
        $allTokens = EfacturaToken::all();
        $activeTokens = $allTokens->filter(fn($t) => $t->isValid());
        $expiringTokens = $activeTokens->filter(fn($t) => now()->diffInDays($t->expires_at, false) <= 30);
        
        $securityDashboard = [
            'active_tokens' => $activeTokens->values()->all(),
            'expiring_tokens' => $expiringTokens->values()->all(),
            'pending_revocations' => [], // Not available in old system
            'total_tokens_issued' => $allTokens->count(),
            'compromised_count' => 0, // Not tracked in old system
        ];

        // Get recent invoices for display from all CUIs (optimized query)
        $invoices = EfacturaInvoice::select([
            '_id', 'cui', 'download_id', 'message_type', 'invoice_number', 
            'invoice_date', 'supplier_name', 'customer_name', 'total_amount', 
            'currency', 'status', 'download_status', 'created_at',
            'pdf_content', 'xml_errors'
        ])
            ->orderBy('created_at', 'desc')
            ->limit(50) // Reduced from 100 for faster loading
            ->get()
            ->map(function ($invoice) {
                return [
                    '_id' => $invoice->_id,
                    'cui' => $invoice->cui, // Include CUI to identify which company
                    'download_id' => $invoice->download_id,
                    'message_type' => $invoice->message_type,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date,
                    'supplier_name' => $invoice->supplier_name,
                    'customer_name' => $invoice->customer_name,
                    'total_amount' => $invoice->total_amount,
                    'currency' => $invoice->currency ?? 'RON',
                    'status' => $invoice->status,
                    'download_status' => $invoice->download_status,
                    'created_at' => $invoice->created_at,
                    'has_pdf' => !empty($invoice->pdf_content),
                    'has_errors' => !empty($invoice->xml_errors)
                ];
            });

        return Inertia::render('Efactura/Index', [
            'hasCredentials' => (bool) $credential,
            'tokenStatus' => $tokenStatus,
            'securityDashboard' => $securityDashboard,
            'tunnelRunning' => false, // Don't check on every page load - too slow
            'invoices' => $invoices
        ]);
    }

    public function getTunnelStatus()
    {
        Log::info('Tunnel status requested', [
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
        
        try {
            return response()->json([
                'success' => true,
                'status' => $this->cloudflaredService->getStatus()
            ]);
        } catch (\Exception $e) {
            Log::error('Tunnel status check failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'status' => ['running' => false, 'message' => 'Error checking status']
            ], 500);
        }
    }

    public function tunnelControl(Request $request)
    {
        $action = $request->get('action', 'status');
        
        Log::info('Tunnel control request received', [
            'action' => $action,
            'user_id' => auth()->id(),
            'request_data' => $request->all(),
            'timestamp' => now()
        ]);
        
        try {
            switch ($action) {
                case 'start':
                    Log::info('Tunnel start requested', [
                        'user_id' => auth()->id(),
                        'timestamp' => now()
                    ]);
                    
                    $success = $this->cloudflaredService->start();
                    
                    Log::info('Tunnel start result', [
                        'success' => $success,
                        'user_id' => auth()->id()
                    ]);
                    
                    return response()->json([
                        'success' => $success,
                        'message' => $success ? 'Tunnel started successfully' : 'Failed to start tunnel',
                        'status' => $this->cloudflaredService->getStatus()
                    ]);
                    
                case 'stop':
                    Log::info('Tunnel stop requested', [
                        'user_id' => auth()->id(),
                        'timestamp' => now()
                    ]);
                    
                    $success = $this->cloudflaredService->stop();
                    
                    Log::info('Tunnel stop result', [
                        'success' => $success,
                        'user_id' => auth()->id()
                    ]);
                    
                    return response()->json([
                        'success' => $success,
                        'message' => $success ? 'Tunnel stopped successfully' : 'Failed to stop tunnel',
                        'status' => $this->cloudflaredService->getStatus()
                    ]);
                    
                case 'status':
                default:
                    return response()->json([
                        'success' => true,
                        'status' => $this->cloudflaredService->getStatus()
                    ]);
            }
        } catch (\Exception $e) {
            Log::error('Tunnel control failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'status' => ['running' => false, 'message' => 'Error checking status']
            ], 500);
        }
    }

    public function authenticate()
    {
        Log::info('E-factura authentication initiated', [
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
        
        // Ensure cloudflared is running before OAuth (only when needed)
        if (!$this->cloudflaredService->isRunning()) {
            $startResult = $this->cloudflaredService->start();
            if (!$startResult) {
                return response()->json(['error' => 'Failed to start tunnel. Please start it manually.'], 500);
            }
        }
        
        $credential = AnafCredential::active()->first();

        if (!$credential) {
            return response()->json(['error' => 'No active ANAF credentials found'], 400);
        }

        // Create OAuth service with correct environment
        $oauthService = new \App\Services\AnafOAuthService($credential->environment);
        
        $authUrl = $oauthService->getAuthorizationUrl(
            $credential->client_id,
            $credential->redirect_uri
        );

        return response()->json(['auth_url' => $authUrl]);
    }

    public function callback(Request $request)
    {
        $code = $request->get('code');
        $error = $request->get('error');
        $errorDescription = $request->get('error_description');
        $state = $request->get('state');

        // Log all callback parameters for debugging
        Log::info('OAuth callback received', [
            'code' => $code ? 'present' : 'missing',
            'error' => $error,
            'error_description' => $errorDescription,
            'state' => $state,
            'all_params' => $request->all()
        ]);

        // Verify state parameter for CSRF protection
        if ($state !== session('oauth_state')) {
            Log::error('OAuth state parameter mismatch', [
                'received_state' => $state,
                'session_state' => session('oauth_state')
            ]);
            return redirect()->route('efactura.index')->with('error', 'Invalid state parameter - possible CSRF attack');
        }

        if ($error) {
            Log::error('OAuth callback error', [
                'error' => $error,
                'error_description' => $errorDescription
            ]);
            return redirect()->route('efactura.index')->with('error', 'Authentication failed: ' . $error . ($errorDescription ? ' - ' . $errorDescription : ''));
        }

        if (!$code) {
            return redirect()->route('efactura.index')->with('error', 'No authorization code received');
        }

        try {
            $credential = AnafCredential::active()->first();

            if (!$credential) {
                return redirect()->route('efactura.index')->with('error', 'No active credentials found');
            }

            // Create OAuth service with correct environment
            $oauthService = new \App\Services\AnafOAuthService($credential->environment);

            // Exchange code for tokens
            $tokenData = $oauthService->exchangeCodeForToken(
                $code,
                $credential->client_id,
                $credential->client_secret,
                $credential->redirect_uri
            );

            // Use the new token service to generate and store tokens securely
            $result = $this->tokenService->generateToken($code);

            return redirect()->route('efactura.index')->with('success', 'Successfully authenticated with ANAF. Token expires in ' . $result['days_until_expiry'] . ' days.');

        } catch (\Exception $e) {
            Log::error('OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect()->route('efactura.index')->with('error', 'Authentication failed: ' . $e->getMessage());
        }
    }

    public function status()
    {
        // Don't check tunnel status on every status call - too slow
        // Only start tunnel when actually needed (during authenticate)
        
        $credential = AnafCredential::active()->first();
        $token = $credential ? EfacturaToken::forClientId($credential->client_id)->active()->first() : null;
        
        // Build token status from existing token system  
        if ($token && $token->isValid()) {
            $daysUntilExpiry = max(0, now()->diffInDays($token->expires_at, false));
            $daysSinceIssued = $token->created_at->diffInDays(now());
            $canRefresh = $daysSinceIssued >= 90;
            
            $status = 'active';
            $message = "Token active, expires in {$daysUntilExpiry} days.";
            
            if ($daysUntilExpiry <= 7) {
                $status = 'expiring_soon';
                $message = "⚠️ Token expires in {$daysUntilExpiry} days!";
            } elseif ($daysUntilExpiry <= 30) {
                $status = 'expiring_warning';
                $message = "Token expires in {$daysUntilExpiry} days.";
            }
            
            $tokenStatus = [
                'has_token' => true,
                'status' => $status,
                'token_id' => 'eft_' . substr(md5($token->id), 0, 8),
                'issued_at' => $token->created_at->toISOString(),
                'expires_at' => $token->expires_at->toISOString(),
                'days_until_expiry' => $daysUntilExpiry,
                'days_since_issued' => $daysSinceIssued,
                'can_refresh' => $canRefresh,
                'days_until_refresh' => $canRefresh ? 0 : (90 - $daysSinceIssued),
                'usage_count' => 0,
                'last_used_at' => $token->updated_at->toISOString(),
                'message' => $message,
            ];
        } else {
            $tokenStatus = [
                'has_token' => false,
                'status' => 'no_token',
                'message' => 'No active token available.'
            ];
        }

        return response()->json([
            'hasCredentials' => (bool) $credential,
            'tokenStatus' => $tokenStatus,
            'tunnelStatus' => $this->cloudflaredService->getStatus()
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

    public function refreshToken()
    {
        try {
            $result = $this->tokenService->refreshToken();
            
            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully. New token expires in ' . $result['days_until_expiry'] . ' days.',
                'tokenStatus' => $this->tokenService->getTokenStatus()
            ]);
        } catch (\Exception $e) {
            Log::error('Token refresh failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function markTokenCompromised(Request $request)
    {
        $request->validate([
            'token_id' => 'required|string',
            'reason' => 'required|string|max:500'
        ]);

        try {
            $this->tokenService->markTokenAsCompromised(
                $request->token_id,
                $request->reason
            );

            return response()->json([
                'success' => true,
                'message' => 'Token marked as compromised. ANAF revocation request has been generated.'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark token as compromised', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function revoke()
    {
        try {
            // Note: This is for backwards compatibility
            // New tokens should be marked as compromised instead for proper audit trail
            $this->oauthService->revokeToken();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Token revocation failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }






}
