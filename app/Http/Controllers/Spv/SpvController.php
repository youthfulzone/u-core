<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Spv\DocumentRequestRequest;
use App\Http\Requests\Spv\MessagesListRequest;
use App\Models\Company;
use App\Models\Spv\SpvMessage;
use App\Models\Spv\SpvRequest;
use App\Services\AnafCompanyService;
use App\Services\AnafSpvService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SpvController extends Controller
{
    public function __construct(
        private AnafSpvService $spvService,
        private AnafCompanyService $companyService
    ) {}

    public function index()
    {
        $user = Auth::user();

        $messages = SpvMessage::forUser((string) $user->id)
            ->recent(60)
            ->orderBy('data_creare', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($message) {
                return $message->append(['has_file_in_database']);
            });

        // Enrich messages with company names from Firme database
        $messages = $this->enrichMessagesWithCompanyNames($messages);
        
        // Debug: Log first message data to verify enrichment
        if ($messages->isNotEmpty()) {
            $firstMessage = $messages->first();
            Log::info('Debug: First message data', [
                'cif' => $firstMessage->cif,
                'company_name' => $firstMessage->company_name ?? 'null',
                'company_source' => $firstMessage->company_source ?? 'null',
                'has_company_name' => isset($firstMessage->company_name),
            ]);
        }

        $requests = SpvRequest::forUser((string) $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Do not auto-create demo data - only show real ANAF data

        // Get API call status (safe - only reads from cache)
        $apiCallStatus = $this->spvService->getApiCallStatus();

        // Get basic session status without triggering any API calls
        $sessionStatus = $this->spvService->getSessionStatus();

        // Create minimal authentication status to avoid any hidden API calls
        $authStatus = [
            'has_automated_auth' => false,
            'methods' => [
                'session_cookies' => [
                    'available' => true,
                    'type' => 'Session Cookies',
                    'description' => 'Browser extension or manual session cookie import',
                    'status' => 'Always available',
                ],
            ],
            'session' => $sessionStatus,
        ];

        return Inertia::render('spv/Index', [
            'messages' => $messages,
            'requests' => $requests,
            'sessionActive' => $authStatus['session']['active'],
            'sessionExpiry' => $authStatus['session']['expires_at'],
            'sessionStatus' => $authStatus['session'],
            'authenticationStatus' => $authStatus,
            'documentTypes' => $this->spvService->getAvailableDocumentTypes(),
            'incomeReasons' => $this->spvService->getIncomeStatementReasons(),
            'apiCallStatus' => $apiCallStatus,
            'sessionValidated' => $authStatus['session']['validated'] ?? false,
            'authenticationStatusText' => $authStatus['session']['authentication_status'] ?? 'not_authenticated',
        ]);
    }

    public function syncMessages(MessagesListRequest $request)
    {
        try {
            $user = Auth::user();
            $days = $request->validated('days', 60);
            $cif = $request->validated('cif');

            Log::info('Sync messages request using session authentication', [
                'user_id' => $user->id,
                'days' => $days,
                'cif' => $cif,
            ]);

            // Use session-based authentication only
            $response = $this->spvService->getMessagesList($days, $cif);

            // Handle empty response or no messages
            if (empty($response) || ! isset($response['mesaje']) || ! is_array($response['mesaje'])) {
                $message = 'No messages found. This may be due to an inactive ANAF session or no messages available for the specified period.';

                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => $message,
                        'synced_count' => 0,
                        'total_messages' => 0,
                        'session_required' => true,
                    ]);
                }

                return back()->with('info', $message);
            }

            $syncedCount = 0;

            foreach ($response['mesaje'] as $messageData) {
                $existingMessage = SpvMessage::where('anaf_id', $messageData['id'])
                    ->where('user_id', (string) $user->id)
                    ->first();

                if (! $existingMessage) {
                    SpvMessage::create([
                        'user_id' => (string) $user->id,
                        'anaf_id' => $messageData['id'],
                        'detalii' => $messageData['detalii'] ?? '',
                        'cif' => $messageData['cif'] ?? '',
                        'data_creare' => $this->parseAnafDate($messageData['data_creare'] ?? ''),
                        'id_solicitare' => $messageData['id_solicitare'] ?? null,
                        'tip' => $messageData['tip'] ?? '',
                        'cnp' => $response['cnp'] ?? '',
                        'cui_list' => is_string($response['cui'] ?? '') ? explode(',', $response['cui']) : [],
                        'serial' => $response['serial'] ?? '',
                        'original_data' => $messageData,
                    ]);
                    $syncedCount++;
                }
            }

            // Extract and queue CUIs for company lookup - check both main cui field and individual messages
            $queuedCompanies = 0;
            $allCuis = collect();

            // Get CUIs from main response
            if (! empty($response['cui'])) {
                $mainCuis = is_string($response['cui']) ? explode(',', $response['cui']) : (array) $response['cui'];
                $allCuis = $allCuis->merge($mainCuis);
            }

            // Get CUIs from individual messages
            foreach ($response['mesaje'] as $messageData) {
                if (!empty($messageData['cif'])) {
                    $allCuis->push($messageData['cif']);
                }
            }

            // Queue unique valid CUIs
            if ($allCuis->isNotEmpty()) {
                $validCuis = $allCuis
                    ->map(fn($cui) => trim($cui))
                    ->filter(fn($cui) => !empty($cui) && preg_match('/^[0-9]{6,9}$/', $cui))
                    ->unique()
                    ->values()
                    ->toArray();
                
                $queuedCompanies = $this->companyService->queueCuisFromMessage($validCuis);
            }

            $message = "Synchronized {$syncedCount} new messages.";
            if ($queuedCompanies > 0) {
                $message .= " {$queuedCompanies} new companies queued for processing.";
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'synced_count' => $syncedCount,
                    'total_messages' => count($response['mesaje']),
                    'queued_companies' => $queuedCompanies,
                ]);
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            Log::error('Sync Messages Failed with Session Authentication', [
                'user_id' => $user->id,
                'days' => $days,
                'error' => $e->getMessage(),
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 400);
            }

            return back()->withErrors(['message' => $e->getMessage()]);
        }
    }

    public function downloadMessage(Request $request, string $messageId): Response|JsonResponse
    {
        try {
            $user = Auth::user();

            Log::info('Download request initiated', [
                'user_id' => $user->id,
                'message_id' => $messageId,
                'user_agent' => $request->userAgent(),
            ]);

            $message = SpvMessage::where('anaf_id', $messageId)
                ->where('user_id', (string) $user->id)
                ->firstOrFail();

            // Check if file is already stored in MongoDB database (atomic storage)
            if ($message->hasFileInDatabase()) {
                Log::info('Serving file from MongoDB database', [
                    'message_id' => $messageId,
                    'file_name' => $message->getFileName(),
                    'file_size' => $message->file_size,
                ]);

                $content = $message->getFileContent();
                $fileName = $message->getFileName();
                $contentType = $message->content_type ?: 'application/pdf';

                return response()->json([
                    'success' => true,
                    'message' => 'Fișierul este deja disponibil pentru vizualizare.',
                    'stored_in_db' => true,
                ]);
            }

            // Check if user already has this file downloaded and cached on disk
            if ($message->isDownloaded() && $message->file_path && Storage::disk('local')->exists($message->file_path)) {
                Log::info('Migrating cached file to MongoDB', [
                    'message_id' => $messageId,
                    'file_path' => $message->file_path,
                ]);

                $cachedContent = Storage::disk('local')->get($message->file_path);
                $contentType = str_ends_with($message->file_path, '.pdf') ? 'application/pdf' : 'application/octet-stream';

                // Store in MongoDB and remove from disk
                $message->storeFileInDatabase($cachedContent, $user->id, $contentType);
                Storage::disk('local')->delete($message->file_path);

                $fileName = $message->getFileName();

                return response()->json([
                    'success' => true,
                    'message' => 'Fișierul a fost migrat în baza de date și este disponibil pentru vizualizare.',
                    'stored_in_db' => true,
                ]);
            }

            // Download fresh from ANAF and store atomically in MongoDB
            Log::info('Downloading fresh from ANAF for MongoDB storage', ['message_id' => $messageId]);
            $response = $this->spvService->downloadMessage($messageId);

            $contentType = $response->header('Content-Type', 'application/octet-stream');
            $fileContent = $response->body();

            // Detect content type
            if (str_contains($contentType, 'application/pdf') || str_starts_with($fileContent, '%PDF')) {
                $contentType = 'application/pdf';
            } elseif (str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml')) {
                $contentType = 'application/xml';
            } elseif (str_contains($contentType, 'text/html')) {
                $contentType = 'text/html';
            }

            // Store file content directly in MongoDB (atomic operation)
            $message->storeFileInDatabase($fileContent, $user->id, $contentType);
            $fileName = $message->getFileName();

            Log::info('Download completed and stored in MongoDB', [
                'message_id' => $messageId,
                'file_name' => $fileName,
                'file_size' => strlen($fileContent),
                'content_type' => $contentType,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Fișierul a fost descărcat și stocat în baza de date pentru vizualizare.',
                'stored_in_db' => true,
                'file_name' => $fileName,
            ]);

        } catch (\Exception $e) {
            Log::error('Download failed', [
                'message_id' => $messageId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 400);
            }

            // For browser requests, return an error page or redirect
            return response()->view('errors.download', [
                'message' => $e->getMessage(),
                'messageId' => $messageId,
            ], 400);
        }
    }

    public function makeDocumentRequest(DocumentRequestRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $validated = $request->validated();

            $spvRequest = SpvRequest::create([
                'user_id' => (string) $user->id,
                'tip' => $validated['tip'],
                'cui' => $validated['cui'],
                'an' => $validated['an'] ?? null,
                'luna' => $validated['luna'] ?? null,
                'motiv' => $validated['motiv'] ?? null,
                'numar_inregistrare' => $validated['numar_inregistrare'] ?? null,
                'cui_pui' => $validated['cui_pui'] ?? null,
                'status' => SpvRequest::STATUS_PENDING,
                'parametri' => $validated,
            ]);

            $response = $this->spvService->makeDocumentRequest(
                $validated['tip'],
                array_filter($validated, fn ($value) => ! is_null($value))
            );

            $spvRequest->markAsProcessed($response);

            return response()->json([
                'success' => true,
                'message' => 'Document request submitted successfully.',
                'request_id' => $spvRequest->id,
                'anaf_response' => $response,
            ]);

        } catch (\Exception $e) {
            if (isset($spvRequest)) {
                $spvRequest->markAsError($e->getMessage());
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    private function parseAnafDate(string $dateString): ?Carbon
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d.m.Y H:i:s', $dateString);
        } catch (\Exception $e) {
            try {
                return Carbon::createFromFormat('d.m.Y', $dateString);
            } catch (\Exception $e) {
                return null;
            }
        }
    }

    public function processDirectAnafData(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $anafData = $request->input('anafData');

            Log::info('Processing direct ANAF data from frontend', [
                'user_id' => $user->id,
                'data_keys' => array_keys($anafData ?? []),
                'has_mesaje' => isset($anafData['mesaje']),
                'mesaje_count' => isset($anafData['mesaje']) ? count($anafData['mesaje']) : 0,
            ]);

            if (! isset($anafData['mesaje']) || ! is_array($anafData['mesaje'])) {
                throw new \Exception('Invalid ANAF data structure. Expected "mesaje" array.');
            }

            $syncedCount = 0;

            foreach ($anafData['mesaje'] as $messageData) {
                if (! isset($messageData['id'])) {
                    continue;
                }

                $existingMessage = SpvMessage::where('anaf_id', $messageData['id'])
                    ->where('user_id', (string) $user->id)
                    ->first();

                if (! $existingMessage) {
                    SpvMessage::create([
                        'user_id' => (string) $user->id,
                        'anaf_id' => $messageData['id'],
                        'detalii' => $messageData['detalii'] ?? '',
                        'cif' => $messageData['cif'] ?? '',
                        'data_creare' => $this->parseAnafDate($messageData['data_creare'] ?? ''),
                        'id_solicitare' => $messageData['id_solicitare'] ?? null,
                        'tip' => $messageData['tip'] ?? '',
                        'cnp' => $anafData['cnp'] ?? '',
                        'cui_list' => is_string($anafData['cui'] ?? '') ? explode(',', $anafData['cui']) : [],
                        'serial' => $anafData['serial'] ?? '',
                        'original_data' => $messageData,
                    ]);
                    $syncedCount++;
                }
            }

            // Extract and queue CUIs from processed direct ANAF data
            $queuedCompanies = 0;
            $allCuis = collect();

            // Get CUIs from main response
            if (! empty($anafData['cui'])) {
                $mainCuis = is_string($anafData['cui']) ? explode(',', $anafData['cui']) : (array) $anafData['cui'];
                $allCuis = $allCuis->merge($mainCuis);
            }

            // Get CUIs from individual messages
            if (!empty($anafData['mesaje']) && is_array($anafData['mesaje'])) {
                foreach ($anafData['mesaje'] as $messageData) {
                    if (!empty($messageData['cif'])) {
                        $allCuis->push($messageData['cif']);
                    }
                }
            }

            // Queue unique valid CUIs
            if ($allCuis->isNotEmpty()) {
                $validCuis = $allCuis
                    ->map(fn($cui) => trim($cui))
                    ->filter(fn($cui) => !empty($cui) && preg_match('/^[0-9]{6,9}$/', $cui))
                    ->unique()
                    ->values()
                    ->toArray();
                
                $queuedCompanies = $this->companyService->queueCuisFromMessage($validCuis);
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully processed {$syncedCount} new messages from direct ANAF call.",
                'synced_count' => $syncedCount,
                'total_messages' => count($anafData['mesaje']),
                'queued_companies' => $queuedCompanies,
            ]);

        } catch (\Exception $e) {
            Log::error('Direct ANAF Data Processing Failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process direct ANAF data: '.$e->getMessage(),
            ], 400);
        }
    }

    public function testAnaf(Request $request)
    {
        try {
            $user = Auth::user();

            // Debug: Show what cookies we have
            $browserCookies = $request->cookies->all();
            $anafCookies = [];
            foreach ($browserCookies as $name => $value) {
                if (str_contains($name, 'JSESSION') || str_contains($name, 'MRH') || str_contains($name, 'F5_')) {
                    $anafCookies[$name] = $value;
                }
            }

            Log::info('Browser cookies analysis', [
                'total_cookies' => count($browserCookies),
                'anaf_cookies' => $anafCookies,
                'all_cookie_names' => array_keys($browserCookies),
            ]);

            // Make a test call to see what ANAF returns
            $response = $this->spvService->getMessagesList(60); // Standard 60 days

            return response()->json([
                'success' => true,
                'message' => 'ANAF test call successful',
                'data' => $response,
                'debug' => [
                    'browser_cookies' => count($browserCookies),
                    'anaf_cookies' => $anafCookies,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => get_class($e),
                'debug' => [
                    'browser_cookies' => $request->cookies->count(),
                    'cookie_names' => array_keys($request->cookies->all()),
                ],
            ], 400);
        }
    }

    public function debugSession(Request $request)
    {
        return response()->json([
            'browser_cookies' => $request->cookies->all(),
            'anaf_session_status' => $this->spvService->getSessionStatus(),
            'request_headers' => $request->headers->all(),
        ]);
    }

    public function autoConnect(Request $request)
    {
        try {
            $user = Auth::user();
            $days = $request->input('days', 60);
            $cif = $request->input('cif');

            // This endpoint will automatically try multiple approaches
            Log::info('Auto connect attempt', [
                'user_id' => $user->id,
                'days' => $days,
                'cif' => $cif,
            ]);

            // Use session-based authentication only
            try {
                $response = $this->spvService->getMessagesList($days, $cif);

                if (isset($response['mesaje']) && is_array($response['mesaje'])) {
                    $syncedCount = 0;

                    foreach ($response['mesaje'] as $messageData) {
                        if (! isset($messageData['id'])) {
                            continue;
                        }

                        $existingMessage = SpvMessage::where('anaf_id', $messageData['id'])
                            ->where('user_id', (string) $user->id)
                            ->first();

                        if (! $existingMessage) {
                            SpvMessage::create([
                                'user_id' => (string) $user->id,
                                'anaf_id' => $messageData['id'],
                                'detalii' => $messageData['detalii'] ?? '',
                                'cif' => $messageData['cif'] ?? '',
                                'data_creare' => $this->parseAnafDate($messageData['data_creare'] ?? ''),
                                'id_solicitare' => $messageData['id_solicitare'] ?? null,
                                'tip' => $messageData['tip'] ?? '',
                                'cnp' => $response['cnp'] ?? '',
                                'cui_list' => is_string($response['cui'] ?? '') ? explode(',', $response['cui']) : [],
                                'serial' => $response['serial'] ?? '',
                                'original_data' => $messageData,
                            ]);
                            $syncedCount++;
                        }
                    }

                    // Extract and queue CUIs from auto-synced messages
                    $queuedCompanies = 0;
                    $allCuis = collect();

                    // Get CUIs from main response
                    if (! empty($response['cui'])) {
                        $mainCuis = is_string($response['cui']) ? explode(',', $response['cui']) : (array) $response['cui'];
                        $allCuis = $allCuis->merge($mainCuis);
                    }

                    // Get CUIs from individual messages
                    if (!empty($response['mesaje']) && is_array($response['mesaje'])) {
                        foreach ($response['mesaje'] as $messageData) {
                            if (!empty($messageData['cif'])) {
                                $allCuis->push($messageData['cif']);
                            }
                        }
                    }

                    // Queue unique valid CUIs
                    if ($allCuis->isNotEmpty()) {
                        $validCuis = $allCuis
                            ->map(fn($cui) => trim($cui))
                            ->filter(fn($cui) => !empty($cui) && preg_match('/^[0-9]{6,9}$/', $cui))
                            ->unique()
                            ->values()
                            ->toArray();
                        
                        $queuedCompanies = $this->companyService->queueCuisFromMessage($validCuis);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => "Successfully synced {$syncedCount} new messages automatically!",
                        'synced_count' => $syncedCount,
                        'total_messages' => count($response['mesaje']),
                        'queued_companies' => $queuedCompanies,
                    ]);
                }
            } catch (\Exception $e) {
                Log::info('Direct ANAF approach failed', ['error' => $e->getMessage()]);
            }

            // If we get here, authentication is required
            return response()->json([
                'success' => false,
                'message' => '🔐 ANAF authentication is required. The system will automatically retry after you authenticate.',
                'requires_auth' => true,
                'auth_url' => "https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile={$days}".($cif ? "&cif={$cif}" : ''),
            ], 401);

        } catch (\Exception $e) {
            Log::error('Auto connect failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Auto connect failed: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getAuthenticationStatus(Request $request): JsonResponse
    {
        try {
            // Get basic session status without triggering any API calls
            $sessionStatus = $this->spvService->getSessionStatus();

            // Create minimal authentication status to avoid any hidden API calls
            $authStatus = [
                'has_automated_auth' => false,
                'methods' => [
                    'session_cookies' => [
                        'available' => true,
                        'type' => 'Session Cookies',
                        'description' => 'Browser extension or manual session cookie import',
                        'status' => 'Always available',
                    ],
                ],
                'session' => $sessionStatus,
            ];

            return response()->json([
                'success' => true,
                'data' => $authStatus,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get authentication status: '.$e->getMessage(),
            ], 500);
        }
    }

    public function clearData(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            // Get current counts
            $messageCount = SpvMessage::forUser((string) $user->id)->count();
            $requestCount = SpvRequest::forUser((string) $user->id)->count();

            Log::info('Clearing SPV data for user', [
                'user_id' => $user->id,
                'message_count' => $messageCount,
                'request_count' => $requestCount,
            ]);

            if ($messageCount === 0 && $requestCount === 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'No SPV data to clear - database is already empty.',
                    'cleared' => [
                        'messages' => 0,
                        'requests' => 0,
                    ],
                ]);
            }

            // Clear all SPV messages for this user
            $deletedMessages = SpvMessage::forUser((string) $user->id)->delete();

            // Clear all SPV requests for this user
            $deletedRequests = SpvRequest::forUser((string) $user->id)->delete();

            Log::info('SPV data cleared successfully', [
                'user_id' => $user->id,
                'deleted_messages' => $deletedMessages,
                'deleted_requests' => $deletedRequests,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Successfully cleared {$deletedMessages} messages and {$deletedRequests} requests.",
                'cleared' => [
                    'messages' => $deletedMessages,
                    'requests' => $deletedRequests,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to clear SPV data', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to clear SPV data: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getApiCallStatus(): JsonResponse
    {
        try {
            $apiCallStatus = $this->spvService->getApiCallStatus();

            return response()->json([
                'success' => true,
                'data' => $apiCallStatus,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get API call status', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get API call status',
            ], 500);
        }
    }

    public function resetApiCounter(): JsonResponse
    {
        try {
            $this->spvService->resetApiCallCounter();

            return response()->json([
                'success' => true,
                'message' => 'API call counter has been reset',
                'data' => $this->spvService->getApiCallStatus(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reset API counter', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset API counter',
            ], 500);
        }
    }

    /**
     * Efficiently enrich SPV messages with company names from Firme database
     */
    private function enrichMessagesWithCompanyNames($messages)
    {
        // Extract all unique CUIs from messages
        $allCuis = collect();
        
        foreach ($messages as $message) {
            // Get CUI from the message CIF field, clean and normalize it
            if (!empty($message->cif)) {
                $cleanCif = preg_replace('/[^0-9]/', '', trim($message->cif));
                if (!empty($cleanCif)) {
                    $allCuis->push($cleanCif);
                }
            }
            
            // Also get CUIs from cui_list if available
            if (!empty($message->cui_list) && is_array($message->cui_list)) {
                foreach ($message->cui_list as $cui) {
                    $cleanCui = preg_replace('/[^0-9]/', '', trim($cui));
                    if (!empty($cleanCui)) {
                        $allCuis->push($cleanCui);
                    }
                }
            }
        }
        
        // Get unique, valid CUIs (6-9 digits)
        $validCuis = $allCuis
            ->filter(fn($cui) => !empty($cui) && preg_match('/^[0-9]{6,9}$/', $cui))
            ->unique()
            ->values()
            ->toArray();
            
        // Bulk lookup company names from Firme database
        $companyNames = collect();
        if (!empty($validCuis)) {
            $companies = Company::whereIn('cui', $validCuis)
                ->select('cui', 'denumire', 'source_api')
                ->get();
                
            $companyNames = $companies->keyBy('cui');
        }
        
        // Enrich each message with company name
        foreach ($messages as $message) {
            $cleanCif = preg_replace('/[^0-9]/', '', trim($message->cif ?? ''));
            
            if (!empty($cleanCif) && $companyNames->has($cleanCif)) {
                $company = $companyNames->get($cleanCif);
                $message->company_name = $company->denumire;
                $message->company_source = $company->source_api;
            } else {
                $message->company_name = null;
                $message->company_source = null;
            }
        }
        
        return $messages;
    }

    /**
     * View file stored in MongoDB directly in browser
     */
    public function viewFile(Request $request, string $messageId): Response
    {
        try {
            $user = Auth::user();
            
            $message = SpvMessage::where('anaf_id', $messageId)
                ->where('user_id', (string) $user->id)
                ->firstOrFail();

            // Check if file is stored in MongoDB
            if (!$message->hasFileInDatabase()) {
                abort(404, 'File not found in database');
            }

            $content = $message->getFileContent();
            $contentType = $message->content_type ?: 'application/pdf';
            $fileName = $message->getFileName();

            Log::info('Serving file for inline view from MongoDB', [
                'message_id' => $messageId,
                'file_name' => $fileName,
                'content_type' => $contentType,
                'file_size' => strlen($content),
            ]);

            return response($content)
                ->header('Content-Type', $contentType)
                ->header('Content-Disposition', 'inline; filename="' . $fileName . '"')
                ->header('Content-Length', (string) strlen($content));

        } catch (\Exception $e) {
            Log::error('File view failed', [
                'message_id' => $messageId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Failed to load file');
        }
    }

    /**
     * Show in-app file viewer page
     */
    public function showViewer(Request $request, string $messageId)
    {
        try {
            $user = Auth::user();
            
            $message = SpvMessage::where('anaf_id', $messageId)
                ->where('user_id', (string) $user->id)
                ->firstOrFail();

            // Check if file is stored in MongoDB
            if (!$message->hasFileInDatabase()) {
                return redirect()->route('spv.index')->with('error', 'Fișierul nu este disponibil pentru vizualizare');
            }

            return Inertia::render('spv/Viewer', [
                'message' => [
                    'id' => $message->id,
                    'anaf_id' => $message->anaf_id,
                    'detalii' => $message->detalii,
                    'cif' => $message->cif,
                    'tip' => $message->tip,
                    'file_name' => $message->getFileName(),
                    'file_size' => $message->file_size,
                    'content_type' => $message->content_type ?: 'application/pdf',
                    'formatted_date_creare' => $message->getFormattedDateCreareAttribute(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('File viewer page failed', [
                'message_id' => $messageId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('spv.index')->with('error', 'Eroare la încărcarea vizualizatorului');
        }
    }
}
