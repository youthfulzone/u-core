<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Spv\DocumentRequestRequest;
use App\Http\Requests\Spv\MessagesListRequest;
use App\Models\Spv\SpvMessage;
use App\Models\Spv\SpvRequest;
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
        private AnafSpvService $spvService
    ) {}

    public function index()
    {
        $user = Auth::user();

        $messages = SpvMessage::forUser((string) $user->id)
            ->recent(60)
            ->orderBy('data_creare', 'desc')
            ->limit(50)
            ->get();

        $requests = SpvRequest::forUser((string) $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Do not auto-create demo data - only show real ANAF data

        // Get comprehensive authentication status
        $authStatus = $this->spvService->getAuthenticationStatus();

        // Get API call status
        $apiCallStatus = $this->spvService->getApiCallStatus();

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

            $message = "Synchronized {$syncedCount} new messages.";

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'synced_count' => $syncedCount,
                    'total_messages' => count($response['mesaje']),
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

            // Check if user already has this file downloaded and cached
            if ($message->isDownloaded() && $message->file_path && Storage::disk('local')->exists($message->file_path)) {
                Log::info('Serving cached file', [
                    'message_id' => $messageId,
                    'file_path' => $message->file_path,
                    'file_size' => $message->file_size,
                ]);

                $cachedContent = Storage::disk('local')->get($message->file_path);
                $cachedFilename = basename($message->file_path);
                $contentType = str_ends_with($cachedFilename, '.pdf') ? 'application/pdf' : 'application/octet-stream';

                return response($cachedContent)
                    ->header('Content-Type', $contentType)
                    ->header('Content-Disposition', "attachment; filename=\"{$cachedFilename}\"")
                    ->header('X-File-Source', 'cached');
            }

            // Download fresh from ANAF
            Log::info('Downloading fresh from ANAF', ['message_id' => $messageId]);
            $response = $this->spvService->downloadMessage($messageId);

            $contentType = $response->header('Content-Type', 'application/octet-stream');
            $contentLength = strlen($response->body());

            // Determine file extension based on content type and content
            $extension = '.bin'; // default
            $filename = "message_{$messageId}_".now()->format('Y-m-d_H-i-s');

            if (str_contains($contentType, 'application/pdf')) {
                $extension = '.pdf';
            } elseif (str_starts_with($response->body(), '%PDF')) {
                // PDF magic number detection
                $extension = '.pdf';
                $contentType = 'application/pdf';
            } elseif (str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml')) {
                $extension = '.xml';
            } elseif (str_contains($contentType, 'text/html')) {
                $extension = '.html';
            }

            $filename .= $extension;
            $filePath = "spv/downloads/{$user->id}/{$filename}";

            // Ensure directory exists
            $directory = dirname($filePath);
            if (! Storage::disk('local')->exists($directory)) {
                Storage::disk('local')->makeDirectory($directory);
            }

            // Store file
            Storage::disk('local')->put($filePath, $response->body());

            // Mark message as downloaded
            $message->markAsDownloaded($user->id, $filePath, $contentLength);

            Log::info('Download completed successfully', [
                'message_id' => $messageId,
                'filename' => $filename,
                'file_size' => $contentLength,
                'content_type' => $contentType,
            ]);

            return response($response->body())
                ->header('Content-Type', $contentType)
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
                ->header('Content-Length', (string) $contentLength)
                ->header('X-File-Source', 'fresh');

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

            return response()->json([
                'success' => true,
                'message' => "Successfully processed {$syncedCount} new messages from direct ANAF call.",
                'synced_count' => $syncedCount,
                'total_messages' => count($anafData['mesaje']),
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

                    return response()->json([
                        'success' => true,
                        'message' => "Successfully synced {$syncedCount} new messages automatically!",
                        'synced_count' => $syncedCount,
                        'total_messages' => count($response['mesaje']),
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
            $authStatus = $this->spvService->getAuthenticationStatus();

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
}
