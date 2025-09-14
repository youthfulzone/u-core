<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEfacturaSync;
use App\Models\AnafCredential;
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
                    'invoice_date' => $invoice->invoice_date?->format('d.m.Y'),
                    'supplier_name' => $invoice->supplier_name,
                    'customer_name' => $invoice->customer_name,
                    'total_amount' => $invoice->total_amount,
                    'currency' => $invoice->currency ?? 'RON',
                    'status' => $invoice->status,
                    'download_status' => $invoice->download_status,
                    'created_at' => $invoice->created_at?->format('d.m.Y H:i'),
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
     * Sync messages from ANAF and store them for all CUIs using queued jobs
     */
    public function syncMessages(Request $request)
    {
        Log::info('E-factura sync messages initiated', [
            'user_id' => auth()->id(),
            'params' => $request->all(),
            'timestamp' => now()
        ]);

        $request->validate([
            'days' => 'integer|min:1|max:60',
            'filter' => 'nullable|in:E,T,P,R',
            'cui' => 'nullable|string', // Optional: sync only specific CUI
            'use_queue' => 'boolean' // Allow fallback to synchronous processing for small batches
        ]);

        try {
            $credential = AnafCredential::active()->first();
            if (!$credential) {
                return response()->json(['error' => 'No active ANAF credentials found'], 400);
            }

            $days = $request->get('days', 30);
            $filter = $request->get('filter');
            $specificCui = $request->get('cui');
            
            $endDate = Carbon::now();
            $startDate = Carbon::now()->subDays($days);

            // Get list of CUIs to sync
            $cuisToSync = [];
            
            if ($specificCui) {
                // Sync only the specified CUI
                $cuisToSync[] = $specificCui;
            } else {
                // Get all companies with valid CUIs from the database
                $companies = \App\Models\Company::whereNotNull('cui')
                    ->where('cui', '!=', '')
                    ->get(['cui', 'denumire']);
                
                foreach ($companies as $company) {
                    // Validate CUI format (should be numeric)
                    if (preg_match('/^[0-9]{6,10}$/', $company->cui)) {
                        $cuisToSync[] = [
                            'cui' => $company->cui,
                            'name' => $company->denumire
                        ];
                    }
                }
            }

            if (empty($cuisToSync)) {
                return response()->json([
                    'error' => 'No valid CUIs found to sync. Please add companies with valid Romanian CUIs first.'
                ], 400);
            }

            $useQueue = $request->get('use_queue', true); // Default to true for high volume
            $syncId = Str::uuid()->toString();

            // For high volume processing, always use queue
            if ($useQueue) {
                Log::info('Dispatching queued sync jobs', [
                    'sync_id' => $syncId,
                    'total_cuis' => count($cuisToSync),
                    'days' => $days,
                    'filter' => $filter
                ]);

                // Set initial sync status
                cache()->put("efactura_sync_status_{$syncId}", [
                    'is_syncing' => true,
                    'sync_id' => $syncId,
                    'status' => 'dispatching_jobs',
                    'total_cuis' => count($cuisToSync),
                    'dispatched_jobs' => 0,
                    'last_update' => now()->toISOString()
                ], 3600);

                $dispatchedJobs = 0;

                // Dispatch one job per CUI for better parallelization
                foreach ($cuisToSync as $cuiInfo) {
                    $cui = is_array($cuiInfo) ? $cuiInfo['cui'] : $cuiInfo;
                    $companyName = is_array($cuiInfo) ? $cuiInfo['name'] : $cui;

                    // Dispatch job with smaller batch size for high volume
                    ProcessEfacturaSync::dispatch(
                        $syncId,
                        $cui,
                        $companyName,
                        $startDate,
                        $endDate,
                        $filter,
                        50 // Batch size of 50 invoices per job
                    )->onQueue('efactura-sync');

                    $dispatchedJobs++;

                    Log::info('Job dispatched for CUI', [
                        'sync_id' => $syncId,
                        'cui' => $cui,
                        'company_name' => $companyName,
                        'job_number' => $dispatchedJobs
                    ]);
                }

                // Update final dispatch status
                cache()->put("efactura_sync_status_{$syncId}", [
                    'is_syncing' => true,
                    'sync_id' => $syncId,
                    'status' => 'jobs_dispatched',
                    'total_cuis' => count($cuisToSync),
                    'dispatched_jobs' => $dispatchedJobs,
                    'last_update' => now()->toISOString()
                ], 3600);

                return response()->json([
                    'success' => true,
                    'sync_id' => $syncId,
                    'message' => "Sync queued successfully. {$dispatchedJobs} jobs dispatched for high-volume processing.",
                    'total_jobs' => $dispatchedJobs,
                    'estimated_invoices' => 'Processing in background - check status for progress'
                ]);

            } else {
                // Fallback to synchronous processing for small batches (legacy behavior)
                return response()->json([
                    'error' => 'Synchronous processing disabled for high-volume operations. Please use queued processing.'
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Message sync failed', ['error' => $e->getMessage()]);
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
                    $messages = $this->efacturaService->getAllMessagesPaginated(
                        $cui,
                        $startDate,
                        $endDate,
                        $filter
                    );
                    
                    Log::info('Messages retrieved for CUI', [
                        'cui' => $cui,
                        'count' => count($messages),
                        'first_message' => !empty($messages) ? $messages[0] : 'no messages',
                        'all_message_ids' => array_map(fn($m) => $m['id'] ?? $m['id_descarcare'] ?? 'no_id', $messages)
                    ]);

                    $cuiSyncedCount = 0;
                    $cuiErrorCount = 0;

                    Log::info('Starting to process messages for CUI', [
                        'cui' => $cui,
                        'company_name' => $companyName,
                        'total_messages' => count($messages),
                        'messages_preview' => array_slice($messages, 0, 3)
                    ]);

                    foreach ($messages as $messageIndex => $message) {
                        try {
                            Log::info('Processing message', [
                                'cui' => $cui,
                                'message_index' => $messageIndex + 1,
                                'total_messages' => count($messages),
                                'message_data' => $message
                            ]);

                            // The API returns 'id' not 'id_descarcare' for the download ID
                            $downloadId = $message['id'] ?? $message['id_descarcare'] ?? null;

                            // Update sync status for current invoice
                            // Only update cache at the start of processing each invoice to reduce spam
                            $currentInvoiceNumber = $messageIndex + 1;
                            $invoiceIdentifier = $message['nr_factura'] ?? "Invoice #{$currentInvoiceNumber}";

                            Log::info('Invoice processing start', [
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'invoice_identifier' => $invoiceIdentifier,
                                'current_number' => $currentInvoiceNumber
                            ]);

                            // Update cache only at the beginning of processing each invoice
                            cache()->put('efactura_sync_status', [
                                'is_syncing' => true,
                                'current_cui' => $cui,
                                'current_company' => $companyName,
                                'current_invoice' => $invoiceIdentifier,
                                'total_invoices' => $results['total_messages'] + count($messages),
                                'processed_invoices' => $results['total_synced'] + $currentInvoiceNumber - 1, // Subtract 1 as we're starting to process
                                'status' => 'processing',
                                'last_error' => null,
                                'last_update' => now()->toISOString()
                            ], 300);
                            
                            // Validate message structure
                            if (!$downloadId) {
                                Log::warning('Message missing download ID - SKIPPING', [
                                    'cui' => $cui,
                                    'message_index' => $messageIndex,
                                    'message' => $message
                                ]);
                                $cuiErrorCount++;
                                continue;
                            }

                            Log::info('Download ID found, checking if invoice exists', [
                                'cui' => $cui,
                                'download_id' => $downloadId
                            ]);
                            
                            // Check if message already exists
                            Log::info('Checking if invoice already exists in database', [
                                'cui' => $cui,
                                'download_id' => $downloadId
                            ]);

                            $existing = EfacturaInvoice::where('download_id', $downloadId)->first();
                            if ($existing) {
                                Log::info('Invoice ALREADY EXISTS - SKIPPING DOWNLOAD', [
                                    'cui' => $cui,
                                    'download_id' => $downloadId,
                                    'invoice_number' => $invoiceIdentifier,
                                    'message_index' => $messageIndex,
                                    'existing_id' => $existing->_id
                                ]);

                                // Still update the progress counter for skipped invoices
                                cache()->put('efactura_sync_status', [
                                    'is_syncing' => true,
                                    'current_cui' => $cui,
                                    'current_company' => $companyName,
                                    'current_invoice' => $invoiceIdentifier . ' (Skip - exists)',
                                    'total_invoices' => $results['total_messages'] + count($messages),
                                    'processed_invoices' => $results['total_synced'] + $currentInvoiceNumber,
                                    'status' => 'processing',
                                    'last_error' => null,
                                    'last_update' => now()->toISOString()
                                ], 300);

                                Log::info('Updated sync status for skipped invoice, continuing to next');
                                // Important: Don't sleep for skipped invoices
                                continue;
                            }

                            Log::info('Invoice does NOT exist - proceeding with download', [
                                'cui' => $cui,
                                'download_id' => $downloadId
                            ]);

                            // Add delay only for invoices that will be downloaded (not skipped)
                            if ($messageIndex > 0) {
                                Log::info('Adding 10 second delay before download', [
                                    'cui' => $cui,
                                    'download_id' => $downloadId,
                                    'message_index' => $messageIndex
                                ]);
                                sleep(10); // 10 seconds delay between invoice downloads for testing
                                Log::info('Delay completed, starting download');
                            }

                            // Download and get complete data structure for atomic MongoDB storage
                            // Wrap in additional try-catch to handle download timeouts
                            Log::info('STARTING INVOICE DOWNLOAD', [
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'service_class' => get_class($this->efacturaService)
                            ]);

                            $downloadStartTime = microtime(true);

                            $downloadData = null;
                            try {
                                $downloadData = $this->efacturaService->downloadMessage($downloadId, $message);
                                $downloadTime = microtime(true) - $downloadStartTime;
                                Log::info('DOWNLOAD COMPLETED SUCCESSFULLY', [
                                    'cui' => $cui,
                                    'download_id' => $downloadId,
                                    'data_keys' => array_keys($downloadData ?? []),
                                    'file_size' => $downloadData['file_size'] ?? 'unknown',
                                    'download_time_seconds' => round($downloadTime, 2)
                                ]);
                            } catch (\Illuminate\Http\Client\RequestException $e) {
                                Log::error('HTTP request failed for invoice download', [
                                    'cui' => $cui,
                                    'download_id' => $downloadId,
                                    'error' => $e->getMessage(),
                                    'response_status' => $e->response?->status(),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                // Skip this invoice and continue with next
                                $cuiErrorCount++;
                                continue;
                            } catch (\Exception $e) {
                                Log::error('CRITICAL: Failed to download invoice', [
                                    'cui' => $cui,
                                    'download_id' => $downloadId,
                                    'error' => $e->getMessage(),
                                    'error_class' => get_class($e),
                                    'trace' => $e->getTraceAsString()
                                ]);

                                // Skip this invoice and continue with next
                                $cuiErrorCount++;
                                continue;
                            }

                            if (!$downloadData) {
                                Log::error('CRITICAL: No download data received', [
                                    'cui' => $cui,
                                    'download_id' => $downloadId
                                ]);
                                $cuiErrorCount++;
                                continue;
                            }
                            
                            $invoiceData = $downloadData['invoice_data'];

                            Log::info('STORING INVOICE IN DATABASE', [
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'invoice_data_keys' => array_keys($invoiceData ?? []),
                                'total_amount' => $invoiceData['total_amount'] ?? 'unknown'
                            ]);

                            $dbStartTime = microtime(true);
                            // Store in database atomically with all content in MongoDB
                            $newInvoice = EfacturaInvoice::create([
                                'cui' => $cui, // Store the actual CUI, not the encrypted one
                                'download_id' => $downloadId,
                                'message_type' => $message['tip'],
                                'invoice_number' => $invoiceData['invoice_number'] ?? null,
                                'invoice_date' => $invoiceData['issue_date'] ?? null,
                                'supplier_name' => $invoiceData['supplier_name'] ?? null,
                                'supplier_tax_id' => $invoiceData['supplier_tax_id'] ?? $message['cif_emitent'] ?? null,
                                'customer_name' => $invoiceData['customer_name'] ?? null,
                                'customer_tax_id' => $invoiceData['customer_tax_id'] ?? $message['cif_beneficiar'] ?? null,
                                'total_amount' => $invoiceData['total_amount'] ?? 0,
                                'currency' => $invoiceData['currency'] ?? 'RON',
                                'xml_content' => $downloadData['xml_content'],
                                'xml_signature' => $downloadData['xml_signature'],
                                'xml_errors' => $downloadData['xml_errors'],
                                'zip_content' => $downloadData['zip_content'],
                                'status' => $invoiceData['status'] ?? 'synced',
                                'download_status' => 'downloaded',
                                'downloaded_at' => $downloadData['downloaded_at'],
                                'archived_at' => now(),
                                'file_size' => $downloadData['file_size']
                            ]);

                            $cuiSyncedCount++;
                            $dbTime = microtime(true) - $dbStartTime;

                            Log::info('INVOICE SUCCESSFULLY STORED', [
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'new_invoice_id' => $newInvoice->_id ?? 'unknown',
                                'cui_synced_count' => $cuiSyncedCount,
                                'total_synced_so_far' => $results['total_synced'] + $cuiSyncedCount,
                                'db_save_time_seconds' => round($dbTime, 2)
                            ]);

                            // Update cache after successful processing with the new invoice ID for real-time updates
                            cache()->put('efactura_sync_status', [
                                'is_syncing' => true,
                                'current_cui' => $cui,
                                'current_company' => $companyName,
                                'current_invoice' => $invoiceIdentifier,
                                'total_invoices' => $results['total_messages'] + count($messages),
                                'processed_invoices' => $results['total_synced'] + $currentInvoiceNumber,
                                'status' => 'processing',
                                'last_error' => null,
                                'last_update' => now()->toISOString(),
                                'new_invoice_id' => $downloadId // Add this for real-time updates
                            ], 300);

                            Log::info('Updated sync status cache after successful processing');

                        } catch (\Exception $e) {
                            Log::error('CRITICAL ERROR: Failed to sync message - continuing with next', [
                                'cui' => $cui,
                                'message_id' => $downloadId ?? 'unknown',
                                'error' => $e->getMessage(),
                                'error_class' => get_class($e),
                                'message_index' => $messageIndex,
                                'trace' => $e->getTraceAsString()
                            ]);
                            $cuiErrorCount++;

                            // Update cache to show error but continue
                            cache()->put('efactura_sync_status', [
                                'is_syncing' => true,
                                'current_cui' => $cui,
                                'current_company' => $companyName,
                                'current_invoice' => 'ERROR - ' . ($invoiceIdentifier ?? 'unknown'),
                                'total_invoices' => $results['total_messages'] + count($messages),
                                'processed_invoices' => $results['total_synced'] + $currentInvoiceNumber,
                                'status' => 'processing',
                                'last_error' => substr($e->getMessage(), 0, 100) . '...',
                                'last_update' => now()->toISOString()
                            ], 300);

                            Log::info('Updated error status cache, continuing to next message');
                            // Continue to next message without stopping
                            continue;
                        }

                        Log::info('MESSAGE PROCESSING COMPLETED', [
                            'cui' => $cui,
                            'message_index' => $messageIndex + 1,
                            'download_id' => $downloadId,
                            'result' => 'success'
                        ]);
                    }

                    $results['synced_by_cui'][] = [
                        'cui' => $cui,
                        'company_name' => $companyName,
                        'messages_found' => count($messages),
                        'synced' => $cuiSyncedCount,
                        'errors' => $cuiErrorCount
                    ];

                    $results['total_synced'] += $cuiSyncedCount;
                    $results['total_errors'] += $cuiErrorCount;
                    $results['total_messages'] += count($messages);

                } catch (\Exception $e) {
                    Log::error('Failed to sync CUI', [
                        'cui' => $cui,
                        'error' => $e->getMessage()
                    ]);
                    
                    $results['synced_by_cui'][] = [
                        'cui' => $cui,
                        'company_name' => $companyName,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Clear sync status
            cache()->put('efactura_sync_status', [
                'is_syncing' => false,
                'current_cui' => null,
                'current_company' => null,
                'current_invoice' => null,
                'total_invoices' => $results['total_messages'],
                'processed_invoices' => $results['total_synced'],
                'status' => 'completed',
                'last_error' => null
            ], 300);

            // Instead of processing synchronously, dispatch job
            \App\Jobs\SyncEfacturaMessages::dispatch($days, $filter, $specificCui);
            
            return response()->json([
                'success' => true,
                'message' => 'Sincronizare pornită în background. Verificați statusul în timp real.',
                'job_dispatched' => true
            ]);

        } catch (\Exception $e) {
            Log::error('Message sync failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download an invoice as PDF
     */
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

            // Check if PDF already exists
            if ($invoice->pdf_content) {
                // Decode base64 if stored as base64
                $pdfContent = base64_decode($invoice->pdf_content);
                return response($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="factura_' . $invoice->invoice_number . '.pdf"'
                ]);
            }

            // Generate PDF from XML using ANAF API
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

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="factura_' . $invoice->invoice_number . '.pdf"'
            ]);

        } catch (\Exception $e) {
            Log::error('PDF download failed', [
                'invoice_id' => $request->invoice_id,
                'error' => $e->getMessage()
            ]);
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
     * Get real-time sync status
     */
    public function getSyncStatus(Request $request)
    {
        try {
            // First set a test status if none exists
            if (!cache()->has('efactura_sync_status')) {
                cache()->put('efactura_sync_status', [
                    'is_syncing' => false,
                    'current_cui' => null,
                    'current_company' => 'Test status - no sync active',
                    'current_invoice' => null,
                    'total_invoices' => 0,
                    'processed_invoices' => 0,
                    'status' => 'idle',
                    'last_error' => null,
                    'test_timestamp' => now()->toISOString()
                ], 600);
                Log::info('Set test sync status in cache');
            }
            
            // Get the latest sync status from cache
            $syncStatus = cache()->get('efactura_sync_status');
            
            // Debug logging
            Log::info('Sync status requested', [
                'cache_status' => $syncStatus,
                'cache_exists' => $syncStatus !== null,
                'user_id' => auth()->id()
            ]);
            
            // If no status in cache, return default
            if (!$syncStatus) {
                $syncStatus = [
                    'is_syncing' => false,
                    'current_cui' => null,
                    'current_company' => null,
                    'current_invoice' => null,
                    'total_invoices' => 0,
                    'processed_invoices' => 0,
                    'status' => 'idle',
                    'last_error' => null
                ];
            }
            
            return response()->json([
                'success' => true,
                'status' => $syncStatus,
                'cache_hit' => cache()->get('efactura_sync_status') !== null
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting sync status', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
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

            $query = EfacturaInvoice::select([
                '_id', 'cui', 'download_id', 'message_type', 'invoice_number',
                'invoice_date', 'supplier_name', 'customer_name', 'total_amount',
                'currency', 'status', 'download_status', 'created_at',
                'pdf_content', 'xml_errors'
            ]);

            if ($since) {
                // Use Carbon for better MongoDB datetime handling
                $sinceDate = \Carbon\Carbon::parse($since);
                $query->where('created_at', '>', $sinceDate);
                Log::info('Filtering invoices since', [
                    'since_original' => $since,
                    'since_parsed' => $sinceDate->toISOString()
                ]);
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
                        'invoice_date' => $invoice->invoice_date?->format('d.m.Y'),
                        'supplier_name' => $invoice->supplier_name,
                        'customer_name' => $invoice->customer_name,
                        'total_amount' => $invoice->total_amount,
                        'currency' => $invoice->currency ?? 'RON',
                        'status' => $invoice->status,
                        'download_status' => $invoice->download_status,
                        'created_at' => $invoice->created_at?->format('d.m.Y H:i'),
                        'has_pdf' => !empty($invoice->pdf_content),
                        'has_errors' => !empty($invoice->xml_errors)
                    ];
                });

            Log::info('Returning invoices', [
                'count' => $invoices->count(),
                'first_3' => $invoices->take(3)->map(fn($inv) => [
                    'id' => $inv->_id,
                    'number' => $inv->invoice_number,
                    'created_at' => $inv->created_at?->toISOString()
                ])
            ]);

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
