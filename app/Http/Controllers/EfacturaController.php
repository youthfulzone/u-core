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

    /**
     * Sync messages from ANAF using simple sequential processing
     */
    public function syncMessages(Request $request)
    {
        Log::info('E-factura sync messages initiated', [
            'user_id' => auth()->id(),
            'params' => $request->all(),
            'timestamp' => now()
        ]);

        try {
            // Check if sync is already running
            if (Cache::get('really_simple_sync_active', false)) {
                return response()->json(['error' => 'Sincronizarea este deja în progres. Vă rugăm să așteptați.'], 409);
            }

            // Check if we have a valid token
            $token = EfacturaToken::first();
            if (!$token || !$token->access_token) {
                return response()->json(['error' => 'No valid e-factura token found'], 400);
            }

            // Generate unique sync ID
            $syncId = \Str::uuid()->toString();

            // Get all companies sorted by CUI (smallest to largest) - MUST be before cache setup
            $companies = Company::whereNotNull('cui')->orderBy('cui')->get();
            $totalCompanies = $companies->count();

            // Clear previous state and start fresh
            Cache::forget('really_simple_last_cui');
            Cache::put('really_simple_total_invoices', 0);
            Cache::put('really_simple_sync_active', true);
            Cache::put('really_simple_sync_status', 'Se inițiază...');

            // Set cache for status endpoint (format expected by frontend)
            Cache::put("efactura_sync_status_{$syncId}", [
                'is_syncing' => true,
                'status' => 'starting',
                'sync_id' => $syncId,
                'current_company' => 0,
                'total_companies' => $totalCompanies,
                'total_processed' => 0,
                'company_name' => 'Se pregătește...',
                'message' => 'Se inițiază sincronizarea...',
                'started_at' => now()->toISOString()
            ]);

            // Also set general status for compatibility
            Cache::put('efactura_sync_status', [
                'is_syncing' => true,
                'status' => 'starting',
                'current_company' => 0,
                'total_companies' => $totalCompanies,
                'total_processed' => 0,
                'company_name' => 'Se pregătește...',
                'message' => 'Se inițiază sincronizarea...'
            ]);

            // Run sync in background using PowerShell for better Windows compatibility
            $cuiList = $companies->pluck('cui')->toArray();
            $cuiListString = implode(',', $cuiList);

            $escapedSyncId = escapeshellarg($syncId);
            $escapedCuiList = escapeshellarg($cuiListString);
            $escapedToken = escapeshellarg($token->access_token);

            $command = "powershell -Command \"Start-Process -FilePath 'C:/Users/TheOldBuffet/.config/herd/bin/php83.bat' " .
                       "-ArgumentList 'artisan', 'efactura:sync-live', {$escapedSyncId}, {$escapedCuiList}, {$escapedToken} " .
                       "-WorkingDirectory '" . base_path() . "' -WindowStyle Hidden -NoNewWindow\"";

            Log::info('Executing background command', ['command' => $command]);

            $output = [];
            $return_var = 0;
            exec($command, $output, $return_var);

            Log::info('Command execution result', [
                'return_code' => $return_var,
                'output' => $output
            ]);

            Log::info('Live sync job dispatched', [
                'sync_id' => $syncId,
                'total_companies' => $totalCompanies
            ]);

            return response()->json([
                'success' => true,
                'sync_id' => $syncId,
                'message' => 'Sincronizarea în timp real a început cu succes',
                'background_sync' => true,
                'total_companies' => $totalCompanies
            ]);

        } catch (\Exception $e) {
            Log::error('Message sync failed', ['error' => $e->getMessage()]);

            // Clear status on error
            Cache::put('really_simple_sync_active', false);
            Cache::put('really_simple_sync_status', 'Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get sync status for queued jobs
     */
    public function getSyncStatus(Request $request)
    {
        $syncId = $request->get('sync_id');

        if ($syncId) {
            // Get specific sync status
            $status = cache()->get("efactura_sync_status_{$syncId}");
        } else {
            // Get legacy sync status (for backward compatibility)
            $status = cache()->get('efactura_sync_status');
        }

        // If no status found, check the really simple sync status
        if (!$status) {
            $isActive = Cache::get('really_simple_sync_active', false);
            $simpleStatus = Cache::get('really_simple_sync_status', 'idle');

            if ($isActive) {
                $status = [
                    'is_syncing' => true,
                    'status' => 'running',
                    'message' => $simpleStatus
                ];
            }
        }

        return response()->json($status ?: [
            'is_syncing' => false,
            'status' => 'idle',
            'message' => 'No active sync operation'
        ]);
    }

    public function downloadPDF(Request $request)
    {
        Log::info('E-factura PDF download requested', [
            'user_id' => auth()->id(),
            'invoice_id' => $request->invoice_id,
            'timestamp' => now()
        ]);

        $request->validate([
            'invoice_id' => 'required|string'
        ]);

        try {
            $invoice = EfacturaInvoice::where('_id', $request->invoice_id)->first();
            if (!$invoice) {
                return response()->json(['error' => 'Invoice not found'], 404);
            }

            // Generate PDF and return it for download
            $pdfData = base64_decode($invoice->pdf_content ?? '');

            if (empty($pdfData)) {
                return response()->json(['error' => 'PDF content not available for this invoice'], 400);
            }

            return response($pdfData, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="factura-' . ($invoice->invoice_number ?? $invoice->download_id) . '.pdf"',
                'Content-Length' => strlen($pdfData)
            ]);

        } catch (\Exception $e) {
            Log::error('Message sync failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * View invoice XML
     */
    public function viewXML(Request $request)
    {
        $request->validate([
            'invoice_id' => 'required|string'
        ]);

        try {
            $invoice = EfacturaInvoice::where('_id', $request->invoice_id)->first();
            if (!$invoice) {
                return response()->json(['error' => 'Invoice not found'], 404);
            }

            if (!$invoice->xml_content) {
                return response()->json(['error' => 'No XML content available'], 400);
            }

            return response($invoice->xml_content, 200, [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'inline; filename="factura_' . $invoice->invoice_number . '.xml"'
            ]);

        } catch (\Exception $e) {
            Log::error('XML view failed', [
                'invoice_id' => $request->invoice_id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear all invoices from database
     */
    public function clearDatabase(Request $request)
    {
        Log::warning('E-factura database clear requested', [
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
        
        try {
            $count = EfacturaInvoice::count();
            EfacturaInvoice::truncate();
            
            Log::info('E-factura database cleared', ['deleted_count' => $count]);
            
            return response()->json([
                'success' => true,
                'message' => "Baza de date a fost golită. {$count} facturi șterse.",
                'deleted_count' => $count
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear e-factura database', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Eroare la ștergerea bazei de date: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Generate PDF from invoice XML
     */
    public function generatePDF(Request $request)
    {
        $request->validate([
            'invoice_id' => 'required|string'
        ]);

        try {
            $invoice = EfacturaInvoice::where('_id', $request->invoice_id)->first();
            if (!$invoice) {
                return response()->json(['error' => 'Invoice not found'], 404);
            }

            // Check if PDF already exists
            if ($invoice->pdf_content) {
                return response()->json([
                    'success' => true,
                    'message' => 'PDF already exists',
                    'has_pdf' => true
                ]);
            }

            // Generate PDF from XML
            if (!$invoice->xml_content) {
                return response()->json(['error' => 'No XML content available for PDF conversion'], 400);
            }

            // Detect standard type from XML
            $standard = 'FACT1'; // Default
            if (strpos($invoice->xml_content, 'urn:cen.eu:en16931') !== false) {
                $standard = 'FCN';
            }

            $pdfContent = $this->efacturaService->convertXmlToPdf($invoice->xml_content, $standard, false);

            if (!$pdfContent) {
                return response()->json(['error' => 'Failed to convert XML to PDF'], 500);
            }

            // Save PDF content for future use (as base64)
            $invoice->update(['pdf_content' => base64_encode($pdfContent)]);

            return response()->json([
                'success' => true,
                'message' => 'PDF generated successfully',
                'has_pdf' => true
            ]);

        } catch (\Exception $e) {
            Log::error('PDF generation failed', [
                'invoice_id' => $request->invoice_id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get recent invoices for real-time updates
     */
    public function getRecentInvoices(Request $request)
    {
        try {
            $since = $request->get('since'); // ISO timestamp to get invoices created after
            $limit = $request->get('limit', 50);

            Log::info('Recent invoices request', [
                'since' => $since,
                'limit' => $limit
            ]);

            // Get recent invoices (latest first)
            $query = EfacturaInvoice::select([
                '_id', 'cui', 'download_id', 'message_type', 'invoice_number',
                'invoice_date', 'supplier_name', 'customer_name', 'total_amount',
                'currency', 'status', 'download_status', 'created_at',
                'pdf_content', 'xml_errors'
            ]);

            // If since timestamp provided, get invoices created after that time
            if ($since) {
                $query->where('created_at', '>', $since);
            }

            $invoices = $query->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($invoice) {
                    return [
                        '_id' => $invoice->_id,
                        'cui' => $invoice->cui,
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

            Log::info('Returning invoices', ['count' => $invoices->count()]);

            return response()->json([
                'success' => true,
                'invoices' => $invoices,
                'count' => $invoices->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting recent invoices', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

}
