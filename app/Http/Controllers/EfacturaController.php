<?php

namespace App\Http\Controllers;

use App\Models\AnafCredential;
use App\Models\EfacturaToken;
use App\Models\EfacturaInvoice;
use App\Services\AnafOAuthService;
use App\Services\EfacturaApiService;
use App\Services\AnafEfacturaService;
use App\Services\CloudflaredService;
use App\Services\EfacturaTokenService;
use Illuminate\Http\Request;
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

    public function authenticate()
    {
        // Ensure cloudflared is running before OAuth (only when needed)
        if (!$this->cloudflaredService->isRunning()) {
            $this->cloudflaredService->start();
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
            'cloudflaredStatus' => ['running' => false, 'message' => 'Checked only when needed']
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
     * Sync messages from ANAF and store them for all CUIs
     */
    public function syncMessages(Request $request)
    {
        $request->validate([
            'days' => 'integer|min:1|max:60',
            'filter' => 'nullable|in:E,T,P,R',
            'cui' => 'nullable|string' // Optional: sync only specific CUI
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

            $results = [
                'total_cuis' => count($cuisToSync),
                'synced_by_cui' => [],
                'total_synced' => 0,
                'total_errors' => 0,
                'total_messages' => 0
            ];

            // Process each CUI
            foreach ($cuisToSync as $cuiInfo) {
                $cui = is_array($cuiInfo) ? $cuiInfo['cui'] : $cuiInfo;
                $companyName = is_array($cuiInfo) ? $cuiInfo['name'] : $cui;
                
                try {
                    // Get messages from ANAF for this CUI
                    $messages = $this->efacturaService->getAllMessagesPaginated(
                        $cui,
                        $startDate,
                        $endDate,
                        $filter
                    );
                    
                    Log::info('Messages retrieved for CUI', [
                        'cui' => $cui,
                        'count' => count($messages),
                        'first_message' => !empty($messages) ? array_keys($messages[0] ?? []) : 'no messages'
                    ]);

                    $cuiSyncedCount = 0;
                    $cuiErrorCount = 0;

                    foreach ($messages as $message) {
                        try {
                            // The API returns 'id' not 'id_descarcare' for the download ID
                            $downloadId = $message['id'] ?? $message['id_descarcare'] ?? null;
                            
                            // Validate message structure
                            if (!$downloadId) {
                                Log::warning('Message missing download ID', [
                                    'cui' => $cui,
                                    'message' => $message
                                ]);
                                $cuiErrorCount++;
                                continue;
                            }
                            
                            // Check if message already exists
                            $existing = EfacturaInvoice::where('download_id', $downloadId)->first();
                            if ($existing) {
                                continue;
                            }

                            // Download and get complete data structure for atomic MongoDB storage
                            $downloadData = $this->efacturaService->downloadMessage($downloadId, $message);
                            
                            $invoiceData = $downloadData['invoice_data'];

                            // Store in database atomically with all content in MongoDB
                            EfacturaInvoice::create([
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
                        } catch (\Exception $e) {
                            Log::error('Failed to sync message', [
                                'cui' => $cui,
                                'message_id' => $downloadId ?? 'unknown',
                                'error' => $e->getMessage()
                            ]);
                            $cuiErrorCount++;
                        }
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

            return response()->json([
                'success' => true,
                'results' => $results
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
                return response($invoice->pdf_content, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="factura_' . $invoice->invoice_number . '.pdf"'
                ]);
            }

            // Generate PDF from XML
            if (!$invoice->xml_content) {
                return response()->json(['error' => 'No XML content available for PDF conversion'], 400);
            }

            $pdfContent = $this->efacturaService->convertToPDF($invoice->xml_content);
            
            // Save PDF content for future use
            $invoice->update(['pdf_content' => $pdfContent]);

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

}
