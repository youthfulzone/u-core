<?php

namespace App\Http\Controllers;

use App\Services\AnafSpvService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnafBrowserSessionController extends Controller
{
    public function __construct(
        private AnafSpvService $spvService
    ) {}

    /**
     * Import browser session cookies for ANAF authentication
     */
    public function importSession(Request $request)
    {
        $request->validate([
            'cookies' => 'required|array',
            'cookies.*' => 'required|string',
        ]);

        try {
            $cookies = $request->input('cookies');

            // Import and validate the session cookies
            $imported = $this->spvService->importSessionCookies($cookies, 'manual');

            if (! $imported) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session cookies validation failed - no valid ANAF authentication found',
                    'error_type' => 'authentication_failed',
                    'recommendation' => 'Please authenticate with ANAF in your browser first',
                ], 400);
            }

            Log::info('Session cookies imported and validated successfully');

            return response()->json([
                'success' => true,
                'message' => 'Session imported and validated successfully',
                'session' => $this->spvService->getSessionStatus(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to import browser session', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to import session: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Proxy ANAF API requests using imported browser cookies
     */
    public function proxyRequest(Request $request, string $endpoint)
    {
        try {
            // Get browser cookies from cache
            $sessionData = Cache::get('anaf_spv_session', []);

            // Handle both old and new format
            $cookies = is_array($sessionData) && isset($sessionData['cookies']) ? $sessionData['cookies'] : $sessionData;

            if (empty($cookies)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active session. Please import browser cookies first.',
                ], 401);
            }

            // Prepare parameters
            $params = $request->all();

            // Make the proxied request
            $response = match ($endpoint) {
                'listaMesaje' => $this->spvService->getMessagesList(
                    $params['zile'] ?? 60,
                    $params['cif'] ?? null,
                    $cookies
                ),
                'descarcare' => $this->spvService->downloadMessage($params['id']),
                default => throw new \Exception("Unknown endpoint: {$endpoint}")
            };

            return response()->json([
                'success' => true,
                'data' => $response,
            ]);

        } catch (\Exception $e) {
            Log::error('ANAF proxy request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            // Check if it's an authentication error
            if (str_contains($e->getMessage(), 'Authentication') ||
                str_contains($e->getMessage(), 'Session')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'requires_auth' => true,
                ], 401);
            }

            return response()->json([
                'success' => false,
                'message' => 'Request failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current session status
     */
    public function sessionStatus()
    {
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
            'session' => $sessionStatus,
            'authentication' => $authStatus,
        ]);
    }

    /**
     * Clear the current session
     */
    public function clearSession()
    {
        $this->spvService->clearSession();

        return response()->json([
            'success' => true,
            'message' => 'Session cleared successfully',
        ]);
    }

    /**
     * Refresh the current session
     */
    public function refreshSession()
    {
        try {
            $refreshed = $this->spvService->refreshSession();

            return response()->json([
                'success' => $refreshed,
                'message' => $refreshed ? 'Session refreshed' : 'Failed to refresh session',
                'session' => $this->spvService->getSessionStatus(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Advanced session capture - extract data from ANAF response text
     */
    public function captureFromResponse(Request $request)
    {
        $request->validate([
            'response_text' => 'required|string',
        ]);

        try {
            $responseText = $request->input('response_text');

            // Try to extract JSON data directly from the response
            $jsonData = null;

            // Method 1: Look for JSON in the response text
            if (preg_match('/\{.*"mesaje".*\}/s', $responseText, $matches)) {
                $jsonData = json_decode($matches[0], true);
            }

            // Method 2: Look for specific ANAF data patterns
            if (! $jsonData && preg_match('/\{.*"cnp".*\}/s', $responseText, $matches)) {
                $jsonData = json_decode($matches[0], true);
            }

            // Method 3: Try parsing the entire response as JSON
            if (! $jsonData) {
                $cleanResponse = trim($responseText);
                if (str_starts_with($cleanResponse, '{') && str_ends_with($cleanResponse, '}')) {
                    $jsonData = json_decode($cleanResponse, true);
                }
            }

            if ($jsonData && isset($jsonData['mesaje'])) {
                Log::info('Successfully extracted ANAF data from response text', [
                    'message_count' => count($jsonData['mesaje']),
                    'cnp' => $jsonData['cnp'] ?? 'not_set',
                    'cui' => $jsonData['cui'] ?? 'not_set',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'ANAF data extracted successfully',
                    'data' => $jsonData,
                ]);
            }

            // If no JSON found, check for authentication errors
            if (str_contains($responseText, 'Certificatul nu a fost prezentat') ||
                str_contains($responseText, 'Pagina logout') ||
                str_contains($responseText, 'autentificare')) {

                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required - please authenticate at ANAF website first',
                    'requires_auth' => true,
                ], 401);
            }

            return response()->json([
                'success' => false,
                'message' => 'No valid ANAF data found in response',
            ], 400);

        } catch (\Exception $e) {
            Log::error('Failed to capture session from response', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process response: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Simple ANAF fetch - requires existing session cookies
     */
    public function simpleFetch(Request $request)
    {
        $request->validate([
            'zile' => 'integer|min:1|max:365',
            'cif' => 'nullable|string',
        ]);

        try {
            $days = $request->input('zile', 60);
            $cif = $request->input('cif');

            // Check if we have an active session
            $sessionStatus = $this->spvService->getSessionStatus();

            if (! $sessionStatus['active']) {
                return response()->json([
                    'success' => false,
                    'requires_auth' => true,
                    'auth_url' => "https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile={$days}".($cif ? '&cif='.urlencode($cif) : ''),
                    'message' => 'No active session. Please authenticate at ANAF first.',
                ]);
            }

            // Try to get messages using existing session
            $response = $this->spvService->getMessagesList($days, $cif);

            return response()->json([
                'success' => true,
                'data' => $response,
                'method' => 'session_cookies',
            ]);

        } catch (\Exception $e) {
            Log::error('ANAF fetch failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ANAF fetch failed: '.$e->getMessage(),
                'requires_auth' => true,
                'auth_url' => 'https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60',
            ], 500);
        }
    }

    /**
     * Receive cookies from browser extension (Enhanced version)
     */
    public function receiveExtensionCookies(Request $request)
    {
        try {
            // Enhanced validation for improved extension
            $validation = $request->validate([
                'cookies' => 'sometimes',
                'source' => 'required|string',
                'timestamp' => 'sometimes|integer',
                'browser_info' => 'sometimes|array',
                'metadata' => 'sometimes|array',
                'trigger' => 'sometimes|string',
                'cookie_count' => 'sometimes|integer',
                'required_count' => 'sometimes|integer',
                'status' => 'sometimes|string',
                'user_agent' => 'sometimes|string',
                'extension_version' => 'sometimes|string',
            ]);

            $cookiesInput = $request->input('cookies');
            $source = $request->input('source');
            $browserInfo = $request->input('browser_info', []);
            $metadata = $request->input('metadata', []);
            $trigger = $request->input('trigger', 'unknown');
            $extensionVersion = $request->input('extension_version');
            $userAgent = $request->input('user_agent');
            $cookieCount = $request->input('cookie_count', 0);
            $requiredCount = $request->input('required_count', 3);
            $cookieStatus = $request->input('status');

            // Handle cookie status reporting (when extension reports cookie counts)
            if ($source === 'browser_extension_status' && $cookieStatus) {
                Log::info('Extension cookie status report', [
                    'status' => $cookieStatus,
                    'cookie_count' => $cookieCount,
                    'required_count' => $requiredCount,
                    'trigger' => $trigger,
                    'extension_version' => $extensionVersion,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Cookie status received: $cookieStatus ($cookieCount/$requiredCount)",
                    'status' => $cookieStatus,
                    'cookie_count' => $cookieCount,
                    'required_count' => $requiredCount,
                    'processing_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2),
                ]);
            }

            // Parse cookies based on format (for actual cookie sync)
            $cookies = [];

            if (! $cookiesInput) {
                throw new \InvalidArgumentException('No cookies provided for sync');
            }

            if (is_string($cookiesInput)) {
                // String format from browser extension
                $cookiePairs = explode('; ', $cookiesInput);
                foreach ($cookiePairs as $pair) {
                    if (strpos($pair, '=') !== false) {
                        [$name, $value] = explode('=', $pair, 2);
                        $cookies[trim($name)] = trim($value);
                    }
                }
            } elseif (is_array($cookiesInput)) {
                // Object format from Python scraper
                $cookies = $cookiesInput;
            } else {
                throw new \InvalidArgumentException('Invalid cookies format');
            }

            Log::info('Enhanced cookie reception from external source', [
                'source' => $source,
                'trigger' => $trigger,
                'cookie_count' => count($cookies),
                'cookie_names' => array_keys($cookies),
                'extension_version' => $extensionVersion,
                'user_agent' => $userAgent ? substr($userAgent, 0, 100) : null,
                'browser_info' => $browserInfo,
                'has_metadata' => ! empty($metadata),
                'request_headers' => [
                    'x-extension-version' => $request->header('X-Extension-Version'),
                    'x-sync-trigger' => $request->header('X-Sync-Trigger'),
                ],
            ]);

            // Store cookies globally for all users if from Python scraper
            if ($source === 'python_scraper') {
                $this->storeGlobalAnafCookies($cookies, $browserInfo, $metadata);
            }

            // Import cookies using the SPV service (validates against ANAF)
            $imported = $this->spvService->importSessionCookies($cookies, $source);

            if (! $imported) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session cookies validation failed - no valid ANAF authentication found',
                    'error_type' => 'authentication_failed',
                    'recommendation' => 'Please authenticate with ANAF in your browser first',
                    'cookie_count' => count($cookies),
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'ANAF session cookies imported and validated successfully',
                'cookie_count' => count($cookies),
                'source' => $source,
                'trigger' => $trigger,
                'extension_version' => $extensionVersion,
                'stored_globally' => $source === 'python_scraper',
                'session_status' => $this->spvService->getSessionStatus(),
                'processing_time_ms' => round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000, 2),
            ]);

        } catch (\Exception $e) {
            Log::error('Extension/scraper cookie import failed', [
                'error' => $e->getMessage(),
                'source' => $request->input('source', 'unknown'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Cookie import failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store ANAF cookies globally for all users to access
     */
    private function storeGlobalAnafCookies(array $cookies, array $browserInfo = [], array $metadata = []): void
    {
        try {
            // Store in cache with a longer TTL since these are scraped cookies
            $cacheKey = 'global_anaf_cookies';
            $cookieData = [
                'cookies' => $cookies,
                'browser_info' => $browserInfo,
                'metadata' => $metadata,
                'scraped_at' => now()->toISOString(),
                'expires_at' => now()->addHours(6)->toISOString(), // 6 hour expiry
            ];

            Cache::put($cacheKey, $cookieData, now()->addHours(6));

            // Also store in database for persistence
            DB::table('anaf_global_sessions')->updateOrInsert(
                ['id' => 1], // Single global session
                [
                    'cookies' => json_encode($cookies),
                    'browser_info' => json_encode($browserInfo),
                    'metadata' => json_encode($metadata),
                    'scraped_at' => now(),
                    'expires_at' => now()->addHours(6),
                    'updated_at' => now(),
                ]
            );

            Log::info('Global ANAF cookies stored successfully', [
                'cookie_count' => count($cookies),
                'cache_key' => $cacheKey,
                'expires_at' => now()->addHours(6)->toISOString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to store global ANAF cookies', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get globally stored ANAF cookies for any user
     */
    public function getGlobalAnafCookies()
    {
        try {
            // Try cache first
            $globalCookies = Cache::get('global_anaf_cookies');

            if (! $globalCookies) {
                // Fallback to database
                $dbRecord = DB::table('anaf_global_sessions')->where('id', 1)->first();
                if ($dbRecord && $dbRecord->expires_at > now()) {
                    $globalCookies = [
                        'cookies' => json_decode($dbRecord->cookies, true),
                        'browser_info' => json_decode($dbRecord->browser_info, true),
                        'metadata' => json_decode($dbRecord->metadata, true),
                        'scraped_at' => $dbRecord->scraped_at,
                        'expires_at' => $dbRecord->expires_at,
                    ];

                    // Restore to cache
                    Cache::put('global_anaf_cookies', $globalCookies, now()->parse($dbRecord->expires_at));
                }
            }

            if ($globalCookies && strtotime($globalCookies['expires_at']) > time()) {
                return response()->json([
                    'success' => true,
                    'has_global_cookies' => true,
                    'cookie_count' => count($globalCookies['cookies']),
                    'scraped_at' => $globalCookies['scraped_at'],
                    'expires_at' => $globalCookies['expires_at'],
                    'browser_info' => $globalCookies['browser_info'],
                ]);
            }

            return response()->json([
                'success' => true,
                'has_global_cookies' => false,
                'message' => 'No valid global ANAF cookies available',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve global ANAF cookies', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve global cookies',
            ], 500);
        }
    }

    /**
     * Use global cookies for current user session
     */
    public function useGlobalCookies()
    {
        try {
            $globalCookies = Cache::get('global_anaf_cookies');

            if (! $globalCookies) {
                // Try database fallback
                $dbRecord = DB::table('anaf_global_sessions')->where('id', 1)->first();
                if ($dbRecord && $dbRecord->expires_at > now()) {
                    $cookies = json_decode($dbRecord->cookies, true);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'No global ANAF cookies available',
                    ], 404);
                }
            } else {
                $cookies = $globalCookies['cookies'];
            }

            // Import global cookies for current user
            $imported = $this->spvService->importSessionCookies($cookies, 'global_scraper');

            if ($imported) {
                return response()->json([
                    'success' => true,
                    'message' => 'Global ANAF cookies imported successfully',
                    'cookie_count' => count($cookies),
                    'session_status' => $this->spvService->getSessionStatus(),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Global cookies validation failed',
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Failed to use global ANAF cookies', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to use global cookies: '.$e->getMessage(),
            ], 500);
        }
    }
}
