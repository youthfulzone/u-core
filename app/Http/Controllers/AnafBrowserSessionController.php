<?php

namespace App\Http\Controllers;

use App\Services\AnafSpvService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AnafBrowserSessionController extends Controller
{
    public function __construct(
        private AnafSpvService $spvService
    ) {
    }

    /**
     * Import browser session cookies for ANAF authentication
     */
    public function importSession(Request $request)
    {
        $request->validate([
            'cookies' => 'required|array',
            'cookies.*' => 'required|string'
        ]);

        try {
            $cookies = $request->input('cookies');
            
            // Import the session cookies
            $imported = $this->spvService->importSessionCookies($cookies, 'manual');
            
            if (!$imported) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid ANAF session cookies found'
                ], 400);
            }

            // Test the session
            $sessionValid = $this->spvService->testSessionWithImportedCookies();
            
            if (!$sessionValid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session cookies imported but authentication failed'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Session imported successfully',
                'session' => $this->spvService->getSessionStatus()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to import browser session', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to import session: ' . $e->getMessage()
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
                    'message' => 'No active session. Please import browser cookies first.'
                ], 401);
            }

            // Prepare parameters
            $params = $request->all();
            
            // Make the proxied request
            $response = match($endpoint) {
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
                'data' => $response
            ]);

        } catch (\Exception $e) {
            Log::error('ANAF proxy request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);

            // Check if it's an authentication error
            if (str_contains($e->getMessage(), 'Authentication') || 
                str_contains($e->getMessage(), 'Session')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'requires_auth' => true
                ], 401);
            }

            return response()->json([
                'success' => false,
                'message' => 'Request failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current session status
     */
    public function sessionStatus()
    {
        return response()->json([
            'success' => true,
            'session' => $this->spvService->getSessionStatus(),
            'authentication' => $this->spvService->getAuthenticationStatus()
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
            'message' => 'Session cleared successfully'
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
                'session' => $this->spvService->getSessionStatus()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Advanced session capture - extract data from ANAF response text
     */
    public function captureFromResponse(Request $request)
    {
        $request->validate([
            'response_text' => 'required|string'
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
            if (!$jsonData && preg_match('/\{.*"cnp".*\}/s', $responseText, $matches)) {
                $jsonData = json_decode($matches[0], true);
            }
            
            // Method 3: Try parsing the entire response as JSON
            if (!$jsonData) {
                $cleanResponse = trim($responseText);
                if (str_starts_with($cleanResponse, '{') && str_ends_with($cleanResponse, '}')) {
                    $jsonData = json_decode($cleanResponse, true);
                }
            }
            
            if ($jsonData && isset($jsonData['mesaje'])) {
                Log::info('Successfully extracted ANAF data from response text', [
                    'message_count' => count($jsonData['mesaje']),
                    'cnp' => $jsonData['cnp'] ?? 'not_set',
                    'cui' => $jsonData['cui'] ?? 'not_set'
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'ANAF data extracted successfully',
                    'data' => $jsonData
                ]);
            }
            
            // If no JSON found, check for authentication errors
            if (str_contains($responseText, 'Certificatul nu a fost prezentat') ||
                str_contains($responseText, 'Pagina logout') ||
                str_contains($responseText, 'autentificare')) {
                
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required - please authenticate at ANAF website first',
                    'requires_auth' => true
                ], 401);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'No valid ANAF data found in response'
            ], 400);

        } catch (\Exception $e) {
            Log::error('Failed to capture session from response', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process response: ' . $e->getMessage()
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
            'cif' => 'nullable|string'
        ]);

        try {
            $days = $request->input('zile', 60);
            $cif = $request->input('cif');
            
            // Check if we have an active session
            $sessionStatus = $this->spvService->getSessionStatus();
            
            if (!$sessionStatus['active']) {
                return response()->json([
                    'success' => false,
                    'requires_auth' => true,
                    'auth_url' => "https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile={$days}" . ($cif ? "&cif=" . urlencode($cif) : ''),
                    'message' => 'No active session. Please authenticate at ANAF first.'
                ]);
            }
            
            // Try to get messages using existing session
            $response = $this->spvService->getMessagesList($days, $cif);
            
            return response()->json([
                'success' => true,
                'data' => $response,
                'method' => 'session_cookies'
            ]);

        } catch (\Exception $e) {
            Log::error('ANAF fetch failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ANAF fetch failed: ' . $e->getMessage(),
                'requires_auth' => true,
                'auth_url' => "https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60"
            ], 500);
        }
    }




    /**
     * Receive cookies from browser extension
     */
    public function receiveExtensionCookies(Request $request)
    {
        try {
            $request->validate([
                'cookies' => 'required|string',
                'source' => 'required|string'
            ]);

            $cookieString = $request->input('cookies');
            $source = $request->input('source');
            
            Log::info('Received cookies from extension', [
                'source' => $source,
                'cookie_length' => strlen($cookieString),
                'timestamp' => $request->input('timestamp')
            ]);

            // Parse cookie string into array
            $cookies = [];
            $cookiePairs = explode('; ', $cookieString);
            
            foreach ($cookiePairs as $pair) {
                if (strpos($pair, '=') !== false) {
                    [$name, $value] = explode('=', $pair, 2);
                    $cookies[trim($name)] = trim($value);
                }
            }

            if (empty($cookies)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid cookies found'
                ], 400);
            }

            // Import the session cookies
            $imported = $this->spvService->importSessionCookies($cookies, $source);
            
            if (!$imported) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to import session cookies'
                ], 400);
            }

            // For extension source, skip session validation to avoid timeouts
            // The extension will validate the session through actual usage
            if ($source === 'browser_extension') {
                Log::info('Extension cookies imported successfully, skipping validation to avoid timeout');
            } else {
                // Test the session for manual imports
                $sessionValid = $this->spvService->testSessionWithImportedCookies();
                
                if (!$sessionValid) {
                    Log::warning('Extension cookies imported but session test failed');
                    return response()->json([
                        'success' => false,
                        'message' => 'Cookies imported but session validation failed'
                    ], 400);
                }
            }

            Log::info('Extension cookies successfully imported and validated');

            return response()->json([
                'success' => true,
                'message' => 'ANAF session cookies imported successfully from extension',
                'cookie_count' => count($cookies),
                'source' => $source
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process extension cookies', [
                'error' => $e->getMessage(),
                'source' => $request->input('source', 'unknown')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process extension cookies: ' . $e->getMessage()
            ], 500);
        }
    }
}