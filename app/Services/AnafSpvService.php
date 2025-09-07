<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnafSpvService
{
    private const BASE_URL = 'https://webserviced.anaf.ro/SPVWS2/rest';

    private const SESSION_CACHE_KEY = 'anaf_spv_session';

    private const SESSION_TIMEOUT = 3 * 60 * 60; // 3 hours

    private const API_TRACKER_CACHE_KEY = 'anaf_api_tracker';

    public function __construct() {}

    public function getMessagesList(int $days = 60, ?string $cif = null, ?array $browserCookies = null): array
    {
        $params = ['zile' => $days];

        if ($cif) {
            $params['cif'] = $cif;
        }

        // Use session cookies only - no certificate authentication
        $response = $this->makeRequest('listaMesaje', $params, $browserCookies);

        Log::info('ANAF Response Details', [
            'status_code' => $response->status(),
            'headers' => $response->headers(),
            'body_preview' => substr($response->body(), 0, 500),
            'content_type' => $response->header('Content-Type'),
        ]);

        if ($response->failed()) {
            $errorMessage = 'ANAF API request failed - Status: '.$response->status().', Body: '.$response->body();
            Log::error($errorMessage);
            throw new \Exception($errorMessage);
        }

        // Check response content and log for debugging
        $contentType = $response->header('Content-Type', '');
        $bodyContent = $response->body();

        Log::info('ANAF Response Analysis', [
            'content_type' => $contentType,
            'status_code' => $response->status(),
            'body_length' => strlen($bodyContent),
            'body_start' => substr($bodyContent, 0, 200),
            'is_html' => str_contains($contentType, 'text/html') || str_contains($bodyContent, '<html>'),
            'has_cert_error' => str_contains($bodyContent, 'Certificatul nu a fost prezentat'),
            'has_logout' => str_contains($bodyContent, 'logout'),
        ]);

        // Check for specific ANAF logout page
        if (str_contains($bodyContent, 'Pagina logout') || str_contains($bodyContent, '<title>Pagina logout</title>')) {
            throw new \Exception('🔐 ANAF Session Expired: ANAF has logged you out. Please visit https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60 to authenticate with your physical token, then try sync again.');
        }

        // Check for certificate authentication errors
        if (str_contains($bodyContent, 'Certificatul nu a fost prezentat')) {
            throw new \Exception('🔐 ANAF Authentication Required: Certificate not presented. Please visit https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60 to authenticate with your physical token, then try sync again.');
        }

        // Check for other logout indicators
        if (str_contains($bodyContent, 'logout') && str_contains($contentType, 'text/html')) {
            throw new \Exception('🔐 ANAF Session Expired: Your authentication session has expired. Please visit https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60 to authenticate, then try sync again.');
        }

        // Try to parse as JSON first, only throw error if it's clearly an auth error HTML page
        if (str_contains($contentType, 'text/html') || str_contains($bodyContent, '<html>')) {
            // Check if it's actually an authentication page
            if (str_contains($bodyContent, 'autentificare') || str_contains($bodyContent, 'login') || str_contains($bodyContent, 'certificate')) {
                throw new \Exception('🔐 ANAF Authentication Error: Please visit https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60 to authenticate with your physical token, then try sync again.');
            }
            // If it's HTML but not clearly an auth page, log and continue to try JSON parsing
            Log::warning('ANAF returned HTML but will attempt JSON parsing', ['content_type' => $contentType]);
        }

        $data = $response->json();

        Log::info('ANAF JSON Response', [
            'data_type' => gettype($data),
            'data_preview' => is_array($data) ? array_keys($data) : $data,
        ]);

        // Ensure we always return an array, even if response is null
        if (! is_array($data)) {
            throw new \Exception('ANAF returned invalid JSON data: '.$response->body());
        }

        if (isset($data['eroare'])) {
            throw new \Exception('ANAF API Error: '.$data['eroare']);
        }

        return $data;
    }

    public function downloadMessage(string $messageId): Response
    {
        Log::info('Starting message download', [
            'message_id' => $messageId,
            'session_active' => $this->isSessionActive(),
        ]);

        $response = $this->makeRequest('descarcare', ['id' => $messageId]);

        if ($response->failed()) {
            Log::error('Message download failed', [
                'message_id' => $messageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to download message: '.$response->body());
        }

        // Check if response is JSON (error) or PDF (success)
        $contentType = $response->header('Content-Type', '');
        $contentLength = $response->header('Content-Length', 0);
        $bodyContent = $response->body();
        $bodyPreview = substr($bodyContent, 0, 500);

        Log::info('Message download response details', [
            'message_id' => $messageId,
            'content_type' => $contentType,
            'content_length' => $contentLength,
            'status' => $response->status(),
            'body_length' => strlen($bodyContent),
            'body_preview' => $bodyPreview,
            'is_pdf' => str_starts_with($bodyContent, '%PDF'),
            'looks_like_json' => str_starts_with(trim($bodyContent), '{'),
        ]);

        // First check if it's a JSON error response
        if (str_contains($contentType, 'application/json') || str_starts_with(trim($bodyContent), '{')) {
            try {
                $data = $response->json();
                if (isset($data['eroare'])) {
                    Log::error('ANAF returned JSON error for download', [
                        'message_id' => $messageId,
                        'error' => $data['eroare'],
                        'full_response' => $data,
                    ]);
                    throw new \Exception('ANAF Error: '.$data['eroare']);
                }
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'ANAF Error:')) {
                    throw $e; // Re-throw ANAF errors
                }
                // If JSON parsing failed but looks like JSON, it might be malformed
                Log::warning('Failed to parse JSON response', [
                    'message_id' => $messageId,
                    'body_preview' => $bodyPreview,
                ]);
            }
        }

        // Check for authentication errors in HTML response
        if (str_contains($contentType, 'text/html') || str_contains($bodyContent, '<html>')) {
            Log::warning('Received HTML response instead of PDF', [
                'message_id' => $messageId,
                'content_type' => $contentType,
                'body_preview' => $bodyPreview,
            ]);

            if (str_contains($bodyContent, 'Certificatul nu a fost prezentat') ||
                str_contains($bodyContent, 'logout') ||
                str_contains($bodyContent, 'autentificare')) {
                Log::warning('Authentication required for download', ['message_id' => $messageId]);
                throw new \Exception('🔐 Authentication required. Please re-authenticate at ANAF and try again.');
            }

            // Generic HTML error
            throw new \Exception('🔐 Received HTML response instead of PDF. Please check your ANAF authentication.');
        }

        // Check if it's actually a PDF
        if (! str_starts_with($bodyContent, '%PDF')) {
            Log::warning('Downloaded content is not a PDF', [
                'message_id' => $messageId,
                'content_type' => $contentType,
                'body_preview' => $bodyPreview,
                'first_4_bytes' => bin2hex(substr($bodyContent, 0, 4)),
            ]);

            // If it's small and might be an error message
            if (strlen($bodyContent) < 1000) {
                throw new \Exception('Downloaded content is not a valid PDF. Response: '.$bodyPreview);
            }
        }

        Log::info('Message download successful', [
            'message_id' => $messageId,
            'file_size' => strlen($bodyContent),
            'content_type' => $contentType,
        ]);

        return $response;
    }

    public function makeDocumentRequest(string $type, array $parameters): array
    {
        $params = array_merge(['tip' => $type], $parameters);

        $response = $this->makeRequest('cerere', $params);

        if ($response->failed()) {
            throw new \Exception('Failed to make document request: '.$response->body());
        }

        $data = $response->json();

        if (isset($data['eroare'])) {
            throw new \Exception($data['eroare']);
        }

        return $data;
    }

    public function updateRequestStatusFromMessages(): int
    {
        $updatedCount = 0;

        // Get all completed requests that haven't received responses yet
        $completedRequests = \App\Models\Spv\SpvRequest::where('status', \App\Models\Spv\SpvRequest::STATUS_COMPLETED)
            ->whereNotNull('response_data')
            ->get();

        foreach ($completedRequests as $request) {
            if (! isset($request->response_data['id_solicitare'])) {
                continue;
            }

            $idSolicitare = $request->response_data['id_solicitare'];

            // Check if there are messages with this id_solicitare
            $relatedMessages = \App\Models\Spv\SpvMessage::where('id_solicitare', $idSolicitare)->exists();

            if ($relatedMessages) {
                $request->markAsResponseReceived();
                $updatedCount++;
            }
        }

        if ($updatedCount > 0) {
            \Illuminate\Support\Facades\Log::info("Updated {$updatedCount} requests to response_received status");
        }

        return $updatedCount;
    }

    public function getAvailableDocumentTypes(): array
    {
        return [
            'D112Contrib' => 'Informatii privind contributiile sociale conform datelor declarate de angajatori',
            'Obligatii de plata' => 'Situatia obligatiilor fiscale de plata neachitate',
            'Nota obligatiilor de plata' => 'Nota obligatiilor de plata',
            'Istoric Spatiu Virtual' => 'Istoric activitati in SPV',
            'Registru intrari-iesiri' => 'Registru intrari-iesiri de documente',
            'Bilant anual' => 'Situatii financiare anuale',
            'D300' => 'Decont de taxa pe valoare adaugata',
            'Istoric declaratii' => 'Istoric declaratii depuse',
            'D390' => 'Declaratie recapitulativa privind livrarile/achizitiile intracomunitare',
            'D100' => 'Declaratie privind obligatiile de plata la bugetul de stat',
            'Bilant semestrial' => 'Rapoarte financiare semestriale',
            'Istoric bilant' => 'Istoricul situatiilor financiare',
            'D205' => 'Declaratie informativa privind impozitul retinut la sursa',
            'D120' => 'Decont privind accizele',
            'D101' => 'Declaratie privind impozitul pe profit',
            'D130' => 'Decont privind impozitul la titeiul din productia interna',
            'D112' => 'Declaratia privind obligatiile de plata a contributiilor sociale',
            'DATE IDENTIFICARE' => 'Informatiile privind datele de identificare',
            'VECTOR FISCAL' => 'Informatiile din vectorul fiscal',
            'Situatie Sintetica' => 'Informatii privind situatia debitelor',
            'D208' => 'Declaratie informativa privind impozitul pe veniturile din transferul proprietatilor',
            'D301' => 'Decont special de taxa pe valoarea adaugata',
            'InterogariBanci' => 'Situatia interogarilor efectuate de banci',
            'Fisa Rol' => 'Fisa pe platitor',
            'D394' => 'Declaratie informativa privind livrarile/prestarile si achizitiile',
            'D392' => 'Declaratie informativa privind livrarile de bunuri si prestarile de servicii',
            'D393' => 'Declaratie informativa privind veniturile din vanzarea de bilete',
            'D180' => 'Nota de certificare',
            'D311' => 'Declaratie privind taxa pe valoare adaugata colectata',
            'D106' => 'Declaratie informativa privind dividentele',
            'Duplicat Recipisa' => 'Duplicat dupa recipisa declaratiilor',
            'Adeverinte Venit' => 'Adeverinta de venit pentru persoana fizica',
            'D212' => 'Duplicat dupa ultimele declaratii unice persoane fizice',
            'NeconcordanteD112CNP' => 'Detalii neconcordante D112 - REVISAL',
            'NeconcordanteD394' => 'Neconcordante actualizate la D394',
        ];
    }

    public function getIncomeStatementReasons(): array
    {
        return [
            'Sanatate',
            'Cresa',
            'Gradinita',
            'Scoala',
            'Liceu',
            'Facultate',
            'Alocatia pentru copiii nou nascuti',
            'Trusou nou nascuti',
            'Alocatia de stat pentru copii',
            'Indemnizatie ajutor stimulent pentru cresterea copilului',
            'Sprijin financiar acordat la constituirea familiei',
            'Alocatia pentru sustinerea familiei',
            'Alocatia familiala complementara',
            'Somaj si stimularea fortei de munca',
            'Ajutor social',
            'Pensie',
            'Stimulent de insertie',
            'Ajutoare pentru incalzirea locuintei',
            'Ajutoare financiare pentru persoane aflate in extrema dificultate',
            'Cheltuieli cu inmormantarea persoanelor din familiile beneficiare de ajutor social',
            'Ajutoare de urgenta in caz de calamitati naturale',
            'Indemnizatia Bugetul personal complementar pentru persoana cu handicap',
            'Alocatia de plasament',
            'Indemnizatia pentru insotitor',
            'Alocatia lunara de hrana pentru copiii cu handicap de tip HIV SIDA',
            'Ajutor anual pentru veteranii de razboi',
            'Institutie financiar bancara asigurare etc.',
            'Executor judecatoresc',
            'Autoritati straine',
            'Altele',
        ];
    }

    private function makeRequest(string $endpoint, array $params = [], ?array $browserCookies = null): Response
    {
        // Rate limiting: 1 request per 2 seconds for ANAF API
        $rateLimitKey = 'anaf_api_rate_limit';
        $lastRequestTime = Cache::get($rateLimitKey);
        
        if ($lastRequestTime) {
            $timeSinceLastRequest = microtime(true) - $lastRequestTime;
            if ($timeSinceLastRequest < 2.0) {
                $waitTime = 2.0 - $timeSinceLastRequest;
                Log::info('ANAF API rate limiting - waiting', [
                    'wait_time_seconds' => $waitTime,
                    'endpoint' => $endpoint
                ]);
                usleep((int)($waitTime * 1000000)); // Convert to microseconds
            }
        }
        
        // Store the current request time
        Cache::put($rateLimitKey, microtime(true), 60); // Cache for 1 minute
        
        // Get or create API call tracker from cache
        $tracker = $this->getApiTracker();

        // Check if we've hit the limit
        if ($tracker['calls_remaining'] <= 0) {
            throw new \Exception('🚫 API call limit reached! You have used all 100 API calls. Reset at: '.Carbon::parse($tracker['reset_at'])->format('Y-m-d H:i:s'));
        }

        $url = self::BASE_URL.'/'.$endpoint;

        Log::info('ANAF SPV API Request', [
            'url' => $url,
            'params' => $params,
            'has_browser_cookies' => ! empty($browserCookies),
            'browser_cookie_count' => $browserCookies ? count($browserCookies) : 0,
            'has_session' => $this->isSessionActive(),
            'api_calls_made' => $tracker['calls_made'],
            'api_calls_remaining' => $tracker['calls_remaining'],
        ]);

        // Increment the counter before making the request
        $this->incrementApiCalls();

        // Create an HTTP client that mimics a real browser session
        $client = Http::withOptions([
            'verify' => false,
            'timeout' => 30,
            'connect_timeout' => 10,
            'allow_redirects' => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'protocols' => ['https'],
            ],
        ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => $endpoint === 'descarcare' ? 'application/pdf, application/json, text/html, */*' : 'application/json, text/html, */*',
                'Accept-Language' => 'ro-RO,ro;q=0.9,en;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Referer' => 'https://webserviced.anaf.ro/',
            ]);

        // Get session cookies if available (prioritize stored session over provided cookies)
        $sessionData = Cache::get(self::SESSION_CACHE_KEY, []);
        $sessionCookies = [];

        if (is_array($sessionData)) {
            // Handle both old and new format
            $cookies = isset($sessionData['cookies']) ? $sessionData['cookies'] : $sessionData;
            if (is_array($cookies)) {
                $sessionCookies = $cookies;
            }
        }

        // Fall back to provided browser cookies if no session
        if (empty($sessionCookies) && $browserCookies) {
            $sessionCookies = $browserCookies;
        }

        if (! empty($sessionCookies)) {
            $cookieStrings = [];
            foreach ($sessionCookies as $name => $value) {
                // Only forward ANAF-related cookies
                if (str_contains($name, 'JSESSION') ||
                    str_contains($name, 'anaf') ||
                    str_contains($name, 'MRH') ||
                    str_contains($name, 'F5_') ||
                    str_contains($name, 'session')) {
                    $cookieStrings[] = "{$name}={$value}";
                }
            }

            if (! empty($cookieStrings)) {
                $client = $client->withHeaders([
                    'Cookie' => implode('; ', $cookieStrings),
                ]);

                Log::info('Using session cookies for ANAF request', [
                    'endpoint' => $endpoint,
                    'cookie_names' => array_keys($sessionCookies),
                    'anaf_cookie_count' => count($cookieStrings),
                    'cookie_string_preview' => substr(implode('; ', $cookieStrings), 0, 100).'...',
                ]);
            }
        } else {
            Log::warning('No session cookies available for ANAF request', [
                'endpoint' => $endpoint,
                'session_active' => $this->isSessionActive(),
            ]);
        }

        $response = $client->get($url, $params);

        // Store session cookies if present
        $cookies = $response->cookies();
        if (count($cookies) > 0) {
            $cookieArray = [];
            $hasDeletedCookies = false;

            foreach ($cookies as $cookie) {
                $cookieValue = $cookie->getValue();
                $cookieArray[$cookie->getName()] = $cookieValue;

                // Check if ANAF is deleting session cookies (indicating session expired)
                if ($cookieValue === 'deleted' || strpos($cookieValue, 'deleted') !== false) {
                    $hasDeletedCookies = true;
                }
            }

            // If ANAF is deleting cookies, clear our session cache
            if ($hasDeletedCookies) {
                Log::info('ANAF session expired - clearing cache', [
                    'deleted_cookies' => array_keys($cookieArray),
                ]);
                $this->clearSession();
            } else {
                // Only store new cookies if they're not deletion markers
                Cache::put(self::SESSION_CACHE_KEY, $cookieArray, self::SESSION_TIMEOUT);

                // Store expiry time for better session management
                Cache::put(self::SESSION_CACHE_KEY.':expire_time', now()->addSeconds(self::SESSION_TIMEOUT)->timestamp, self::SESSION_TIMEOUT + 60);

                Log::info('ANAF session cookies stored', [
                    'cookie_count' => count($cookieArray),
                    'cookie_names' => array_keys($cookieArray),
                    'expires_at' => now()->addSeconds(self::SESSION_TIMEOUT)->toDateTimeString(),
                ]);
            }
        }

        // Track errors if response failed
        if ($response->failed()) {
            $tracker = $this->getApiTracker();
            $errorMessage = "API call #{$tracker['calls_made']} failed - Status: {$response->status()}, Endpoint: {$endpoint}";
            $this->addApiError($errorMessage);
            Log::error($errorMessage);
        } else {
            // Mark session as validated on successful API response (lazy validation)
            $this->markSessionAsValidated();
        }

        $updatedTracker = $this->getApiTracker();
        Log::info('ANAF SPV API Response', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'api_calls_remaining' => $updatedTracker['calls_remaining'],
        ]);

        return $response;
    }

    private function getApiTracker(): array
    {
        $tracker = Cache::get(self::API_TRACKER_CACHE_KEY);

        // Initialize if not exists or reset if expired
        if (! $tracker || (isset($tracker['reset_at']) && Carbon::parse($tracker['reset_at'])->isPast())) {
            $tracker = [
                'calls_made' => 0,
                'calls_limit' => 100,
                'calls_remaining' => 100,
                'errors' => [],
                'reset_at' => now()->addHours(24)->toIso8601String(),
            ];
            Cache::put(self::API_TRACKER_CACHE_KEY, $tracker, 86400); // 24 hours
        }

        return $tracker;
    }

    private function incrementApiCalls(): void
    {
        $tracker = $this->getApiTracker();
        $tracker['calls_made']++;
        $tracker['calls_remaining'] = max(0, $tracker['calls_limit'] - $tracker['calls_made']);
        Cache::put(self::API_TRACKER_CACHE_KEY, $tracker, 86400);
    }

    private function addApiError(string $error): void
    {
        $tracker = $this->getApiTracker();
        $tracker['errors'][] = [
            'error' => $error,
            'timestamp' => now()->toIso8601String(),
            'call_number' => $tracker['calls_made'],
        ];
        // Keep only last 10 errors
        $tracker['errors'] = array_slice($tracker['errors'], -10);
        Cache::put(self::API_TRACKER_CACHE_KEY, $tracker, 86400);
    }

    public function getApiCallStatus(): array
    {
        $tracker = $this->getApiTracker();

        return [
            'calls_made' => $tracker['calls_made'],
            'calls_limit' => $tracker['calls_limit'],
            'calls_remaining' => $tracker['calls_remaining'],
            'reset_at' => $tracker['reset_at'],
            'recent_errors' => array_slice($tracker['errors'], -5), // Last 5 errors
        ];
    }

    public function resetApiCallCounter(): void
    {
        $tracker = [
            'calls_made' => 0,
            'calls_limit' => 100,
            'calls_remaining' => 100,
            'errors' => [],
            'reset_at' => now()->addHours(24)->toIso8601String(),
        ];
        Cache::put(self::API_TRACKER_CACHE_KEY, $tracker, 86400);
    }

    public function getAuthenticationUrl(int $days = 60): string
    {
        return self::BASE_URL.'/listaMesaje?zile='.$days;
    }

    public function setSessionFromBrowser(array $cookies): void
    {
        Cache::put(self::SESSION_CACHE_KEY, $cookies, self::SESSION_TIMEOUT);
    }

    public function clearSession(): void
    {
        Cache::forget(self::SESSION_CACHE_KEY);
    }

    public function importSessionCookies(array $cookies, string $source = 'manual'): bool
    {
        try {
            // Enhanced validation for ANAF session cookies
            $requiredCookies = ['MRHSession', 'F5_ST', 'LastMRH_Session']; // Core ANAF session cookies
            $optionalCookies = ['JSESSIONID']; // Optional but helpful

            $foundCookies = [];
            $hasRequired = false;

            // Check which required cookies we have
            foreach ($requiredCookies as $cookieName) {
                if (isset($cookies[$cookieName]) && ! empty($cookies[$cookieName])) {
                    $foundCookies[] = $cookieName;
                }
            }

            // Also check for any session-related cookies as fallback
            foreach ($cookies as $name => $value) {
                if (in_array($name, $requiredCookies) || in_array($name, $optionalCookies) ||
                    str_contains($name, 'session') || str_contains($name, 'JSESSION') || str_contains($name, 'MRH')) {
                    $hasRequired = true;
                }
            }

            // Detect expired session: only LastMRH_Session remains
            if (count($foundCookies) === 1 && in_array('LastMRH_Session', $foundCookies)) {
                Log::warning('ANAF session appears to be expired - only LastMRH_Session cookie found', [
                    'found_cookies' => array_keys($cookies),
                    'source' => $source,
                ]);

                // Clear any existing session
                $this->clearSession();

                return false;
            }

            // Require exactly 3 core cookies for a valid session
            if (count($foundCookies) < 3) {
                Log::warning('Insufficient ANAF session cookies for valid session - exactly 3 required', [
                    'required_cookies' => $requiredCookies,
                    'found_cookies' => $foundCookies,
                    'found_count' => count($foundCookies),
                    'required_count' => 3,
                    'all_cookies' => array_keys($cookies),
                    'source' => $source,
                ]);

                return false;
            }

            if (! $hasRequired) {
                Log::warning('No valid ANAF session cookies found', ['cookies' => array_keys($cookies)]);

                return false;
            }

            // Store cookies WITHOUT validation to avoid API calls
            $sessionData = [
                'cookies' => $cookies,
                'source' => $source,
                'imported_at' => now()->toDateTimeString(),
                'expires_at' => now()->addSeconds(self::SESSION_TIMEOUT)->timestamp,
                'validated' => false, // Mark as not validated yet
            ];

            Cache::put(self::SESSION_CACHE_KEY, $sessionData, self::SESSION_TIMEOUT);

            // Store expiry time
            Cache::put(self::SESSION_CACHE_KEY.':expire_time', now()->addSeconds(self::SESSION_TIMEOUT)->timestamp, self::SESSION_TIMEOUT + 60);

            Log::info('ANAF session cookies imported (validation deferred)', [
                'cookie_count' => count($cookies),
                'cookie_names' => array_keys($cookies),
                'source' => $source,
                'expires_at' => now()->addSeconds(self::SESSION_TIMEOUT)->toDateTimeString(),
                'validation_status' => 'deferred',
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to import session cookies', ['error' => $e->getMessage()]);

            // Make sure to clear any partially stored session
            $this->clearSession();

            return false;
        }
    }

    public function testSessionWithImportedCookies(): bool
    {
        // Simply check if session is active in cache - no API calls
        // Session validity will be verified when actually needed (during sync)
        $isActive = $this->isSessionActive();

        Log::info('Session test completed without API call', [
            'session_active' => $isActive,
            'method' => 'cache_check_only',
        ]);

        return $isActive;
    }

    public function isSessionActive(): bool
    {
        return Cache::has(self::SESSION_CACHE_KEY);
    }

    public function getSessionExpiryTime(): ?Carbon
    {
        if (! $this->isSessionActive()) {
            return null;
        }

        // Get the cache store and calculate expiry time
        $cacheStore = Cache::getStore();
        $expiryTime = $cacheStore->get(self::SESSION_CACHE_KEY.':expire_time', 0);

        if ($expiryTime > 0) {
            return Carbon::createFromTimestamp($expiryTime);
        }

        // Fallback: estimate expiry based on session timeout
        return now()->addSeconds(self::SESSION_TIMEOUT);
    }

    public function getSessionRemainingTime(): ?int
    {
        $expiryTime = $this->getSessionExpiryTime();

        if (! $expiryTime) {
            return null;
        }

        $remaining = now()->diffInSeconds($expiryTime, false);

        return max(0, (int) $remaining);
    }

    public function isSessionExpiringSoon(int $thresholdMinutes = 30): bool
    {
        $remaining = $this->getSessionRemainingTime();

        if ($remaining === null) {
            return false;
        }

        return $remaining <= ($thresholdMinutes * 60);
    }

    public function refreshSession(): bool
    {
        if (! $this->isSessionActive()) {
            return false;
        }

        try {
            // Make a lightweight request to refresh the session
            $this->makeRequest('listaMesaje', ['zile' => 60]);

            return true;
        } catch (\Exception $e) {
            Log::warning('Failed to refresh ANAF session', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function getSessionStatus(): array
    {
        $remainingSeconds = $this->getSessionRemainingTime();
        $isActive = $this->isSessionActive() && $remainingSeconds > 0;
        $sessionData = Cache::get(self::SESSION_CACHE_KEY, []);

        // Handle both old and new format
        $cookies = is_array($sessionData) && isset($sessionData['cookies']) ? $sessionData['cookies'] : $sessionData;
        $source = is_array($sessionData) && isset($sessionData['source']) ? $sessionData['source'] : 'unknown';
        $validated = is_array($sessionData) && isset($sessionData['validated']) ? $sessionData['validated'] : false;

        // Enhanced cookie validation analysis
        $requiredCookies = ['MRHSession', 'F5_ST', 'LastMRH_Session'];
        $foundRequiredCookies = [];
        $sessionQuality = 'unknown';

        if ($isActive && is_array($cookies)) {
            foreach ($requiredCookies as $cookieName) {
                if (isset($cookies[$cookieName]) && ! empty($cookies[$cookieName])) {
                    $foundRequiredCookies[] = $cookieName;
                }
            }

            // Determine session quality based on available cookies (exactly 3 required)
            if (count($foundRequiredCookies) === 3) {
                $sessionQuality = 'excellent'; // All 3 cookies present - only valid state
            } elseif (count($foundRequiredCookies) === 2) {
                $sessionQuality = 'incomplete'; // 2 cookies - insufficient for operation
                $isActive = false; // Mark as inactive - need exactly 3
            } elseif (count($foundRequiredCookies) === 1 && in_array('LastMRH_Session', $foundRequiredCookies)) {
                $sessionQuality = 'expired'; // Only LastMRH_Session - session expired
                $isActive = false; // Mark as inactive if expired
            } elseif (count($foundRequiredCookies) === 1) {
                $sessionQuality = 'insufficient'; // Only one cookie - insufficient
                $isActive = false; // Mark as inactive
            } else {
                $sessionQuality = 'invalid'; // No required cookies
                $isActive = false;
            }
        }

        return [
            'active' => $isActive,
            'validated' => $validated, // Only true after actual validation with ANAF
            'expires_at' => $this->getSessionExpiryTime()?->toDateTimeString(),
            'remaining_seconds' => $remainingSeconds,
            'remaining_minutes' => $remainingSeconds ? round($remainingSeconds / 60, 1) : null,
            'expiring_soon' => $this->isSessionExpiringSoon(),
            'cookie_names' => $isActive ? array_keys($cookies) : [],
            'required_cookies_found' => $foundRequiredCookies,
            'required_cookies_missing' => array_diff($requiredCookies, $foundRequiredCookies),
            'session_quality' => $sessionQuality,
            'source' => $source,
            'imported_at' => is_array($sessionData) && isset($sessionData['imported_at']) ? $sessionData['imported_at'] : null,
            'authentication_status' => $isActive ? ($validated ? 'authenticated' : 'pending_validation') :
                ($sessionQuality === 'expired' ? 'session_expired' : 'not_authenticated'),
        ];
    }

    /**
     * Get authentication status - simplified to session cookies only
     */
    public function getAuthenticationStatus(): array
    {
        $methods = [];

        // Only session-based authentication is available
        $methods['session_cookies'] = [
            'available' => true,
            'type' => 'Session Cookies',
            'description' => 'Browser extension or manual session cookie import',
            'status' => 'Always available',
        ];

        return [
            'has_automated_auth' => false, // No automated auth - relies on extension
            'methods' => $methods,
            'session' => $this->getSessionStatus(),
        ];
    }

    // Removed - no certificate authentication needed

    public function validateSession(): bool
    {
        if (! $this->isSessionActive()) {
            return false;
        }

        try {
            // Try a simple request to validate the session
            $response = $this->makeRequest('listaMesaje', ['zile' => 60]);

            // Check if we got an authentication error response
            $contentType = $response->header('Content-Type', '');
            if (str_contains($contentType, 'text/html') || str_contains($response->body(), '<html>')) {
                $this->clearSession();

                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->clearSession();

            return false;
        }
    }

    private function markSessionAsValidated(): void
    {
        $sessionData = Cache::get(self::SESSION_CACHE_KEY, []);

        // Only mark as validated if not already validated
        if (is_array($sessionData) && (! isset($sessionData['validated']) || ! $sessionData['validated'])) {
            $sessionData['validated'] = true;
            Cache::put(self::SESSION_CACHE_KEY, $sessionData, self::SESSION_TIMEOUT);

            Log::info('Session marked as validated after successful API call', [
                'source' => $sessionData['source'] ?? 'unknown',
            ]);
        }
    }
}
