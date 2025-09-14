<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEfacturaSync;
use App\Jobs\ProcessEfacturaSequential;
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

        $activeCredential = AnafCredential::active()->first();
        $activeToken = null;

        if ($activeCredential) {
            $activeToken = EfacturaToken::forClientId($activeCredential->client_id)
                ->active()
                ->first();
        }

        $recentInvoices = EfacturaInvoice::orderBy('archived_at', 'desc')
            ->limit(10)
            ->get();

        // Build token status object that matches frontend interface
        $daysUntilExpiry = 0;
        if ($activeToken && $activeToken->expires_at) {
            $daysUntilExpiry = max(0, now()->diffInDays($activeToken->expires_at, false));
        }

        $tokenStatus = [
            'has_token' => !!$activeToken,
            'status' => $activeToken ? ($daysUntilExpiry < 7 ? 'expiring_soon' : 'active') : 'no_token',
            'token_id' => $activeToken?->_id,
            'issued_at' => $activeToken?->created_at?->toISOString(),
            'expires_at' => $activeToken?->expires_at?->toISOString(),
            'days_until_expiry' => $daysUntilExpiry,
            'days_since_issued' => $activeToken ? now()->diffInDays($activeToken->created_at) : 0,
            'can_refresh' => !!$activeToken,
            'message' => $activeToken
                ? ($daysUntilExpiry < 7
                    ? "Token expires in {$daysUntilExpiry} days - consider refreshing"
                    : "Token is active and valid ({$daysUntilExpiry} days remaining)")
                : 'No active token found'
        ];

        $tunnelStatus = $this->cloudflaredService->getStatus();

        return Inertia::render('Efactura/Index', [
            'hasCredentials' => !!$activeCredential,
            'tokenStatus' => $tokenStatus,
            'securityDashboard' => [
                'active_tokens' => $activeToken ? [$activeToken] : [],
                'expiring_tokens' => [],
                'pending_revocations' => [],
                'total_tokens_issued' => $activeToken ? 1 : 0,
                'compromised_count' => 0
            ],
            'tunnelRunning' => $tunnelStatus['running'] ?? false,
            'invoices' => $recentInvoices
        ]);
    }

    public function authenticate(Request $request)
    {
        try {
            $request->validate([
                'client_id' => 'required|string',
                'client_secret' => 'required|string',
            ]);

            // Start the authentication flow
            $authUrl = $this->oauthService->getAuthorizationUrl(
                $request->client_id,
                $request->client_secret
            );

            return response()->json([
                'success' => true,
                'auth_url' => $authUrl,
                'message' => 'Please authorize the application in the popup window.'
            ]);

        } catch (\Exception $e) {
            Log::error('Authentication failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function callback(Request $request)
    {
        Log::info('OAuth callback received', [
            'query_params' => $request->query(),
            'timestamp' => now()
        ]);

        try {
            if ($request->has('error')) {
                $errorDescription = $request->get('error_description', $request->get('error'));
                Log::error('OAuth callback error', ['error' => $errorDescription]);

                return view('oauth-callback-result', [
                    'success' => false,
                    'message' => "Authentication failed: {$errorDescription}"
                ]);
            }

            if (!$request->has('code')) {
                Log::error('OAuth callback missing authorization code');
                return view('oauth-callback-result', [
                    'success' => false,
                    'message' => 'Authorization code not received'
                ]);
            }

            // Exchange code for token
            $result = $this->oauthService->exchangeCodeForToken($request->get('code'));

            return view('oauth-callback-result', [
                'success' => true,
                'message' => 'Authentication successful! You can close this window.',
                'token_info' => [
                    'expires_at' => $result['expires_at'] ?? null,
                    'scopes' => $result['scopes'] ?? []
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('OAuth callback processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return view('oauth-callback-result', [
                'success' => false,
                'message' => "Authentication processing failed: {$e->getMessage()}"
            ]);
        }
    }

    public function status()
    {
        try {
            $activeCredential = AnafCredential::active()->first();
            $activeToken = null;

            if ($activeCredential) {
                $activeToken = EfacturaToken::forClientId($activeCredential->client_id)
                    ->active()
                    ->first();
            }

            return response()->json([
                'hasActiveCredential' => !!$activeCredential,
                'hasActiveToken' => !!$activeToken,
                'tokenExpiresAt' => $activeToken?->expires_at,
                'credentialInfo' => $activeCredential ? [
                    'client_id' => $activeCredential->client_id,
                    'created_at' => $activeCredential->created_at
                ] : null
            ]);

        } catch (\Exception $e) {
            Log::error('Status check failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getTunnelStatus()
    {
        try {
            return response()->json($this->cloudflaredService->getStatus());
        } catch (\Exception $e) {
            Log::error('Tunnel status check failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function tunnelControl(Request $request)
    {
        try {
            $request->validate([
                'action' => 'required|in:start,stop,restart'
            ]);

            $action = $request->get('action');

            switch ($action) {
                case 'start':
                    $result = $this->cloudflaredService->start();
                    break;
                case 'stop':
                    $result = $this->cloudflaredService->stop();
                    break;
                case 'restart':
                    $result = $this->cloudflaredService->restart();
                    break;
                default:
                    return response()->json(['error' => 'Invalid action'], 400);
            }

            return response()->json([
                'success' => $result,
                'action' => $action,
                'status' => $this->cloudflaredService->getStatus()
            ]);

        } catch (\Exception $e) {
            Log::error('Tunnel control failed', [
                'action' => $request->get('action'),
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function refreshToken()
    {
        try {
            $this->tokenService->refreshTokenIfNeeded();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Token refresh failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
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
            $this->oauthService->revokeToken();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Token revocation failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Sync messages from ANAF using queued jobs for high-volume processing
     */
    public function syncMessages(Request $request)
    {
        Log::info('E-factura sync messages initiated', [
            'user_id' => auth()->id(),
            'params' => $request->all(),
            'timestamp' => now(),
            'route_name' => $request->route()->getName(),
            'url' => $request->fullUrl()
        ]);

        $request->validate([
            'days' => 'integer|min:1|max:60',
            'filter' => 'nullable|in:E,T,P,R',
            'cui' => 'nullable|string',
            'use_queue' => 'boolean'
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
                $cuisToSync[] = $specificCui;
            } else {
                $companies = \App\Models\Company::whereNotNull('cui')
                    ->where('cui', '!=', '')
                    ->get(['cui', 'denumire']);

                foreach ($companies as $company) {
                    if (preg_match('/^[0-9]{6,10}$/', $company->cui)) {
                        $cuisToSync[] = [
                            'cui' => $company->cui,
                            'name' => $company->denumire
                        ];
                    }
                }

                // Sort companies by CUI (lowest to highest)
                usort($cuisToSync, function($a, $b) {
                    return (int)$a['cui'] <=> (int)$b['cui'];
                });
            }

            if (empty($cuisToSync)) {
                return response()->json([
                    'error' => 'No valid CUIs found to sync. Please add companies with valid Romanian CUIs first.'
                ], 400);
            }

            $useQueue = $request->get('use_queue', true);
            $syncId = Str::uuid()->toString();

            if ($useQueue) {
                Log::info('Dispatching sequential sync job', [
                    'sync_id' => $syncId,
                    'total_companies' => count($cuisToSync),
                    'companies' => array_map(fn($c) => $c['cui'] . ' - ' . $c['name'], $cuisToSync),
                    'days' => $days,
                    'filter' => $filter
                ]);

                // Set initial sync status
                $initialStatus = [
                    'is_syncing' => true,
                    'sync_id' => $syncId,
                    'status' => 'starting',
                    'current_company' => 0,
                    'total_companies' => count($cuisToSync),
                    'current_invoice' => 0,
                    'total_invoices_for_company' => 0,
                    'total_processed' => 0,
                    'total_errors' => 0,
                    'test_mode' => false,
                    'last_update' => now()->toISOString()
                ];

                cache()->put("efactura_sync_status_{$syncId}", $initialStatus, 3600);

                // Also store generic sync status for frontend polling
                cache()->put("efactura_sync_status", $initialStatus, 3600);

                // Dispatch single sequential job
                ProcessEfacturaSequential::dispatch(
                    $syncId,
                    $cuisToSync, // All companies sorted by CUI
                    $startDate,
                    $endDate,
                    $filter,
                    false // Production mode, no test delays
                )->onQueue('efactura-sync');

                Log::info('Sequential job dispatched', [
                    'sync_id' => $syncId,
                    'total_companies' => count($cuisToSync)
                ]);

                return response()->json([
                    'success' => true,
                    'sync_id' => $syncId,
                    'message' => "Sequential sync started. Processing " . count($cuisToSync) . " companies one by one.",
                    'total_companies' => count($cuisToSync),
                    'processing_mode' => 'sequential',
                    'job_dispatched' => true,
                    'note' => 'Production mode: 4 second delays between invoices'
                ]);

            } else {
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
            $status = cache()->get("efactura_sync_status_{$syncId}");
        } else {
            $status = cache()->get('efactura_sync_status');
        }

        return response()->json($status ?: [
            'is_syncing' => false,
            'status' => 'idle',
            'message' => 'No active sync operation'
        ]);
    }

    /**
     * Download an invoice as PDF - generated on demand only
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

            // Store PDF in database for future requests
            $invoice->update([
                'pdf_content' => base64_encode($pdfContent),
                'pdf_generated_at' => now()
            ]);

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
     * Generate PDF for an invoice without downloading it
     */
    public function generatePDF(Request $request)
    {
        Log::info('E-factura PDF generation requested', [
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
                return response()->json([
                    'success' => true,
                    'message' => 'PDF already generated',
                    'generated_at' => $invoice->pdf_generated_at
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

            // Store PDF in database
            $invoice->update([
                'pdf_content' => base64_encode($pdfContent),
                'pdf_generated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PDF generated successfully',
                'generated_at' => now()->toISOString()
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
     * View XML content of an invoice
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
    public function clearDatabase()
    {
        try {
            $count = EfacturaInvoice::count();
            EfacturaInvoice::truncate();

            Log::info('E-factura database cleared', [
                'user_id' => auth()->id(),
                'deleted_count' => $count,
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => "Database cleared successfully. {$count} invoices deleted."
            ]);

        } catch (\Exception $e) {
            Log::error('Database clear failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get recent invoices for the frontend table
     */
    public function getRecentInvoices(Request $request)
    {
        try {
            $limit = $request->get('limit', 20);
            $offset = $request->get('offset', 0);

            $invoices = EfacturaInvoice::orderBy('archived_at', 'desc')
                ->skip($offset)
                ->limit($limit)
                ->get([
                    '_id',
                    'cui',
                    'invoice_number',
                    'invoice_date',
                    'supplier_name',
                    'customer_name',
                    'total_amount',
                    'currency',
                    'status',
                    'archived_at',
                    'pdf_generated_at'
                ]);

            $totalCount = EfacturaInvoice::count();

            return response()->json([
                'invoices' => $invoices,
                'total_count' => $totalCount,
                'has_more' => ($offset + $limit) < $totalCount
            ]);

        } catch (\Exception $e) {
            Log::error('Get recent invoices failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}