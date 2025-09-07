<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TargetareApiService
{
    private string $baseUrl = 'https://api.targetare.ro/v1';
    private ?string $bearerToken;

    public function __construct()
    {
        $this->bearerToken = config('services.targetare.bearer_token');
        
        if (!$this->bearerToken) {
            Log::error('Targetare API bearer token not configured');
        }
    }

    /**
     * Get company data from Targetare API with 24-hour caching
     * If not cached, fetches both company and financial data to populate cache
     */
    public function getCompanyData(string $cui): array
    {
        $companyCacheKey = "targetare_company_{$cui}";
        $financialCacheKey = "targetare_financial_{$cui}";
        
        // Check if company data is already cached
        if (Cache::has($companyCacheKey)) {
            return Cache::get($companyCacheKey);
        }
        
        // Data not in cache, fetch BOTH endpoints to populate cache for 24 hours
        Log::info('Company data not cached, fetching both company and financial data', [
            'cui' => $cui
        ]);
        
        // Fetch company data
        $companyResult = $this->fetchCompanyData($cui);
        
        if ($companyResult['success']) {
            // Also fetch financial data to cache it
            $financialResult = $this->fetchCompanyFinancialData($cui);
            
            // Cache both results for 24 hours
            Cache::put($companyCacheKey, $companyResult, 24 * 60 * 60);
            Cache::put($financialCacheKey, $financialResult, 24 * 60 * 60);
            
            Log::info('Cached both company and financial data for 24 hours', [
                'cui' => $cui,
                'company_success' => $companyResult['success'],
                'financial_success' => $financialResult['success']
            ]);
        } else {
            // Cache the failure for company data
            Cache::put($companyCacheKey, $companyResult, 24 * 60 * 60);
        }
        
        return $companyResult;
    }

    /**
     * Get company financial data from Targetare API with 24-hour caching
     * If not cached, fetches both company and financial data to populate cache
     */
    public function getCompanyFinancialData(string $cui): array
    {
        $companyCacheKey = "targetare_company_{$cui}";
        $financialCacheKey = "targetare_financial_{$cui}";
        
        // Check if financial data is already cached
        if (Cache::has($financialCacheKey)) {
            return Cache::get($financialCacheKey);
        }
        
        // Data not in cache, fetch BOTH endpoints to populate cache for 24 hours
        Log::info('Financial data not cached, fetching both company and financial data', [
            'cui' => $cui
        ]);
        
        // Fetch company data first
        $companyResult = $this->fetchCompanyData($cui);
        
        if ($companyResult['success']) {
            // Also fetch financial data
            $financialResult = $this->fetchCompanyFinancialData($cui);
            
            // Cache both results for 24 hours
            Cache::put($companyCacheKey, $companyResult, 24 * 60 * 60);
            Cache::put($financialCacheKey, $financialResult, 24 * 60 * 60);
            
            Log::info('Cached both company and financial data for 24 hours', [
                'cui' => $cui,
                'company_success' => $companyResult['success'],
                'financial_success' => $financialResult['success']
            ]);
            
            return $financialResult;
        } else {
            // If company data fails, financial will likely fail too
            $financialResult = [
                'success' => false,
                'error' => 'Company data fetch failed, skipping financial data',
                'source' => 'targetare',
            ];
            
            // Cache both failures
            Cache::put($companyCacheKey, $companyResult, 24 * 60 * 60);
            Cache::put($financialCacheKey, $financialResult, 24 * 60 * 60);
            
            return $financialResult;
        }
    }

    /**
     * Get both company and financial data in one call to avoid duplicate API requests
     * This method should be used when both datasets are needed
     */
    public function getCompanyWithFinancialData(string $cui): array
    {
        // Use the individual methods which now handle smart caching
        // If either is cached, both will be cached (since they cache together)
        $companyResult = $this->getCompanyData($cui);
        $financialResult = $this->getCompanyFinancialData($cui);
        
        // Combine results
        $combinedResult = [
            'success' => $companyResult['success'],
            'company_data' => $companyResult['success'] ? $companyResult['data'] : null,
            'financial_data' => $financialResult['success'] ? $financialResult['data'] : null,
            'remaining_requests' => $companyResult['remaining_requests'] ?? $financialResult['remaining_requests'] ?? null,
            'source' => 'targetare',
        ];
        
        Log::info('Targetare combined data assembled from cached individual results', [
            'cui' => $cui,
            'company_success' => $companyResult['success'],
            'financial_success' => $financialResult['success'],
            'has_company_data' => !empty($combinedResult['company_data']),
            'has_financial_data' => !empty($combinedResult['financial_data'])
        ]);
        
        return $combinedResult;
    }

    /**
     * Fetch company data from Targetare API
     */
    private function fetchCompanyData(string $cui): array
    {
        if (!$this->bearerToken) {
            return [
                'success' => false,
                'error' => 'Targetare API bearer token not configured',
                'source' => 'targetare',
            ];
        }

        try {
            // Rate limiting: 1 request per 2 seconds for Targetare API
            $rateLimitKey = 'targetare_api_rate_limit';
            $lastRequestTime = Cache::get($rateLimitKey);
            if ($lastRequestTime) {
                $timeSinceLastRequest = microtime(true) - $lastRequestTime;
                if ($timeSinceLastRequest < 2.0) {
                    $waitTime = 2.0 - $timeSinceLastRequest;
                    usleep((int)($waitTime * 1000000));
                }
            }
            
            Cache::put($rateLimitKey, microtime(true));

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->bearerToken,
                'Accept' => 'application/json',
            ])->timeout(30)->get("{$this->baseUrl}/companies/{$cui}");

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('Targetare API company data fetched successfully', [
                    'cui' => $cui,
                    'remaining_requests' => $data['remainingRequests'] ?? null,
                    'success' => $data['success'] ?? false,
                ]);

                $result = [
                    'success' => true,
                    'data' => $data['data'] ?? null,
                    'remaining_requests' => $data['remainingRequests'] ?? null,
                    'source' => 'targetare',
                ];

                // Update the dedicated remaining requests cache
                if (isset($data['remainingRequests'])) {
                    Cache::put('targetare_remaining_requests', $data['remainingRequests'], 60 * 60); // 1 hour
                }

                return $result;
            }

            Log::warning('Targetare API request failed', [
                'cui' => $cui,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => 'API request failed with status: ' . $response->status(),
                'source' => 'targetare',
            ];

        } catch (\Exception $e) {
            Log::error('Targetare API error', [
                'cui' => $cui,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'source' => 'targetare',
            ];
        }
    }

    /**
     * Fetch company financial data from Targetare API
     */
    private function fetchCompanyFinancialData(string $cui): array
    {
        if (!$this->bearerToken) {
            return [
                'success' => false,
                'error' => 'Targetare API bearer token not configured',
                'source' => 'targetare',
            ];
        }

        try {
            // Rate limiting: 1 request per 2 seconds for Targetare API
            $rateLimitKey = 'targetare_api_rate_limit';
            $lastRequestTime = Cache::get($rateLimitKey);
            if ($lastRequestTime) {
                $timeSinceLastRequest = microtime(true) - $lastRequestTime;
                if ($timeSinceLastRequest < 2.0) {
                    $waitTime = 2.0 - $timeSinceLastRequest;
                    usleep((int)($waitTime * 1000000));
                }
            }
            
            Cache::put($rateLimitKey, microtime(true));

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->bearerToken,
                'Accept' => 'application/json',
            ])->timeout(30)->get("{$this->baseUrl}/companies/{$cui}/financial");

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('Targetare API financial data fetched successfully', [
                    'cui' => $cui,
                    'remaining_requests' => $data['remainingRequests'] ?? null,
                    'success' => $data['success'] ?? false,
                ]);

                $result = [
                    'success' => true,
                    'data' => $data['data'] ?? null,
                    'remaining_requests' => $data['remainingRequests'] ?? null,
                    'source' => 'targetare',
                ];

                // Update the dedicated remaining requests cache
                if (isset($data['remainingRequests'])) {
                    Cache::put('targetare_remaining_requests', $data['remainingRequests'], 60 * 60); // 1 hour
                }

                return $result;
            }

            Log::warning('Targetare API financial request failed', [
                'cui' => $cui,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => 'Financial API request failed with status: ' . $response->status(),
                'source' => 'targetare',
            ];

        } catch (\Exception $e) {
            Log::error('Targetare API financial error', [
                'cui' => $cui,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'source' => 'targetare',
            ];
        }
    }

    /**
     * Get API remaining requests count from cache or fetch fresh
     */
    public function getRemainingRequests(string $cui = '1860712'): ?int
    {
        // Check for dedicated remaining requests cache first
        $remainingRequests = Cache::get('targetare_remaining_requests');
        if ($remainingRequests !== null) {
            return $remainingRequests;
        }

        // Try to get from the combined cache for this CUI
        $combinedData = Cache::get("targetare_combined_{$cui}");
        if ($combinedData && isset($combinedData['remaining_requests'])) {
            // Cache the remaining requests separately for faster access
            Cache::put('targetare_remaining_requests', $combinedData['remaining_requests'], 60 * 60); // 1 hour
            return $combinedData['remaining_requests'];
        }

        // Try to get from company cache for this CUI
        $companyData = Cache::get("targetare_company_{$cui}");
        if ($companyData && isset($companyData['remaining_requests'])) {
            // Cache the remaining requests separately for faster access
            Cache::put('targetare_remaining_requests', $companyData['remaining_requests'], 60 * 60); // 1 hour
            return $companyData['remaining_requests'];
        }

        // Try to get from financial cache for this CUI
        $financialData = Cache::get("targetare_financial_{$cui}");
        if ($financialData && isset($financialData['remaining_requests'])) {
            // Cache the remaining requests separately for faster access
            Cache::put('targetare_remaining_requests', $financialData['remaining_requests'], 60 * 60); // 1 hour
            return $financialData['remaining_requests'];
        }

        // If no cached data at all, make a fresh request to get remaining count
        // This should only happen on the very first load when no cache exists
        Log::info('No cached Targetare data found, making fresh API call for remaining requests', [
            'default_cui' => $cui
        ]);
        
        $result = $this->fetchCompanyData($cui);
        $remainingRequests = $result['remaining_requests'] ?? null;
        
        // Cache the remaining requests for quick access on subsequent page loads
        if ($remainingRequests !== null) {
            Cache::put('targetare_remaining_requests', $remainingRequests, 60 * 60); // 1 hour
        }
        
        return $remainingRequests;
    }

}