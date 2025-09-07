<?php

namespace App\Services;

use App\Jobs\ProcessCompanyQueue;
use App\Models\Company;
use App\Models\CompanyQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnafCompanyService
{
    private const LISTA_FIRME_URL = 'https://lista-firme.info/api/v1/info';

    private const RATE_LIMIT_KEY = 'lista_firme_rate_limit';

    private const RATE_LIMIT_SECONDS = 2;

    public function __construct(
        private TargetareApiService $targetareService
    ) {}

    public function queueCuisFromMessage(array $cuis): int
    {
        $queuedCount = 0;

        foreach ($cuis as $cui) {
            $cui = trim($cui);

            if (empty($cui)) {
                continue;
            }

            // Check if company already exists
            if (Company::where('cui', $cui)->exists()) {
                Log::debug('CUI already exists in companies table', ['cui' => $cui]);
                continue;
            }

            // Auto-approve companies from synced messages - skip Accept/Reject step
            Company::create([
                'cui' => $cui,
                'denumire' => 'Se încarcă...', // Temporary loading state
                'status' => 'approved', // Auto-approve to skip manual review
                'approved_at' => now(),
                'approved_by' => 'system', // Mark as system-approved
                'synced_at' => null, // Will be updated when data is fetched
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $queuedCount++;
        }

        Log::info('Companies auto-approved for processing', [
            'registered_count' => $queuedCount,
            'total_cuis' => count($cuis),
            'cuis_processed' => $cuis,
        ]);

        return $queuedCount;
    }

    public function getPendingQueue(): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyQueue::pending()->orderBy('created_at')->get();
    }

    public function processCompanyQueue(string $cui): array
    {
        $queueItem = CompanyQueue::where('cui', $cui)->where('status', 'pending')->first();

        if (! $queueItem) {
            throw new \Exception("CUI {$cui} not found in queue or already processed");
        }

        // Update status to processing
        $queueItem->update(['status' => 'processing']);

        try {
            // Rate limiting - ensure 2 seconds between requests
            $this->enforceRateLimit();

            // Call Lista Firme API
            $companyData = $this->fetchCompanyFromListaFirme($cui);

            if (! $companyData || empty($companyData['name'])) {
                throw new \Exception('Company not found in Lista Firme database');
            }

            // Structure data consistently
            $structuredData = [
                'found' => [
                    [
                        'cui' => $cui,
                        'denumire' => $companyData['name'],
                        'adresa' => $this->buildAddressFromAlternative($companyData),
                        'nrRegCom' => $companyData['reg_com'] ?? null,
                        'telefon' => $companyData['info']['phone'] ?? null,
                        'fax' => $companyData['info']['fax'] ?? null,
                        'codPostal' => $companyData['address']['postalCode'] ?? null,
                        'data_source' => 'Lista-firme.info',

                        // Lista Firme specific fields
                        'euid' => $companyData['euid'] ?? null,
                        'registration_date' => $companyData['registration_date'] ?? null,
                        'company_type' => $companyData['type'] ?? null,
                        'address_details' => $companyData['address'] ?? null,
                        'status_details' => $companyData['status'] ?? null,
                        'caen_codes' => $companyData['caen'] ?? [],
                        'full_address_info' => $companyData['info'] ?? null,
                        'registration_status' => $companyData['info']['registrationStatus'] ?? null,
                        'activity_code' => $companyData['info']['activityCode'] ?? null,
                        'bank_account' => $companyData['info']['bankAccount'] ?? null,
                        'ro_invoice_status' => $companyData['info']['roInvoiceStatus'] ?? null,
                        'authority_name' => $companyData['info']['authorityName'] ?? null,
                        'form_of_ownership' => $companyData['info']['formOfOwnership'] ?? null,
                        'organizational_form' => $companyData['info']['organizationalForm'] ?? null,
                        'legal_form' => $companyData['info']['legalForm'] ?? null,
                    ],
                ],
                'notFound' => [],
            ];

            // Update queue with fetched data
            $queueItem->update([
                'anaf_data' => $structuredData,
                'company_name' => $companyData['name'],
            ]);

            return [
                'success' => true,
                'data' => $structuredData,
                'message' => 'Company data fetched successfully from Lista Firme',
            ];

        } catch (\Exception $e) {
            // Reset status to pending on error
            $queueItem->update(['status' => 'pending']);

            Log::error('Failed to fetch company data from Lista Firme', [
                'cui' => $cui,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function approveCompany(string $cui): bool
    {
        $queueItem = CompanyQueue::where('cui', $cui)->first();

        if (! $queueItem) {
            return false;
        }

        // If no company data exists, fetch it from Lista Firme API
        if (! $queueItem->anaf_data) {
            Log::info('No company data found for CUI, fetching from Lista Firme API', ['cui' => $cui]);

            try {
                // Update status to processing
                $queueItem->update(['status' => 'processing']);

                // Enforce rate limiting
                $this->enforceRateLimit();

                // Fetch company data from Lista Firme only
                $companyData = $this->fetchCompanyFromListaFirme($cui);

                if (! $companyData || empty($companyData['name'])) {
                    throw new \Exception('Company not found in Lista Firme database');
                }

                // Store the fetched data in same structure for consistency
                $structuredData = [
                    'found' => [
                        [
                            'cui' => $cui,
                            'denumire' => $companyData['name'],
                            'adresa' => $this->buildAddressFromAlternative($companyData),
                            'nrRegCom' => $companyData['reg_com'] ?? null,
                            'telefon' => $companyData['info']['phone'] ?? null,
                            'fax' => $companyData['info']['fax'] ?? null,
                            'codPostal' => $companyData['address']['postalCode'] ?? null,
                            'data_source' => 'Lista-firme.info',

                            // Lista Firme specific fields
                            'euid' => $companyData['euid'] ?? null,
                            'registration_date' => $companyData['registration_date'] ?? null,
                            'company_type' => $companyData['type'] ?? null,
                            'address_details' => $companyData['address'] ?? null,
                            'status_details' => $companyData['status'] ?? null,
                            'caen_codes' => $companyData['caen'] ?? [],
                            'full_address_info' => $companyData['info'] ?? null,
                            'registration_status' => $companyData['info']['registrationStatus'] ?? null,
                            'activity_code' => $companyData['info']['activityCode'] ?? null,
                            'bank_account' => $companyData['info']['bankAccount'] ?? null,
                            'ro_invoice_status' => $companyData['info']['roInvoiceStatus'] ?? null,
                            'authority_name' => $companyData['info']['authorityName'] ?? null,
                            'form_of_ownership' => $companyData['info']['formOfOwnership'] ?? null,
                            'organizational_form' => $companyData['info']['organizationalForm'] ?? null,
                            'legal_form' => $companyData['info']['legalForm'] ?? null,
                        ],
                    ],
                    'notFound' => [],
                ];

                $queueItem->update([
                    'anaf_data' => $structuredData,
                    'company_name' => $companyData['name'],
                ]);

                Log::info('Company data fetched from Lista Firme successfully', [
                    'cui' => $cui,
                    'company_name' => $companyData['name'],
                ]);

            } catch (\Exception $e) {
                // Reset status to pending on error
                $queueItem->update(['status' => 'pending']);

                Log::error('Failed to fetch company data from Lista Firme during approval', [
                    'cui' => $cui,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        // Create company from queue data
        $companyData = $queueItem->anaf_data;

        if (isset($companyData['found']) && count($companyData['found']) > 0) {
            $company = $companyData['found'][0];

            Company::create([
                // Core company information
                'cui' => $cui,
                'denumire' => $company['denumire'] ?? null,
                'adresa' => $company['adresa'] ?? null,
                'nrRegCom' => $company['nrRegCom'] ?? null,
                'telefon' => $company['telefon'] ?? null,
                'fax' => $company['fax'] ?? null,
                'codPostal' => $company['codPostal'] ?? null,
                'data_source' => $company['data_source'] ?? 'Lista-firme.info',
                'synced_at' => now(),

                // Lista Firme specific fields
                'euid' => $company['euid'] ?? null,
                'registration_date' => $company['registration_date'] ?? null,
                'company_type' => $company['company_type'] ?? null,
                'address_details' => $company['address_details'] ?? null,
                'status_details' => $company['status_details'] ?? null,
                'caen_codes' => $company['caen_codes'] ?? [],
                'full_address_info' => $company['full_address_info'] ?? null,
                'registration_status' => $company['registration_status'] ?? null,
                'activity_code' => $company['activity_code'] ?? null,
                'bank_account' => $company['bank_account'] ?? null,
                'ro_invoice_status' => $company['ro_invoice_status'] ?? null,
                'authority_name' => $company['authority_name'] ?? null,
                'form_of_ownership' => $company['form_of_ownership'] ?? null,
                'organizational_form' => $company['organizational_form'] ?? null,
                'legal_form' => $company['legal_form'] ?? null,
            ]);

            Log::info('Company created successfully from Lista Firme data', [
                'cui' => $cui,
                'company_name' => $company['denumire'] ?? 'N/A',
            ]);
        } else {
            Log::warning('Company not found in Lista Firme database', [
                'cui' => $cui,
                'lista_firme_response' => $companyData,
            ]);

            // Mark as rejected since company doesn't exist in Lista Firme
            $queueItem->update([
                'status' => 'rejected',
                'processed_at' => now(),
                'processed_by' => 'system',
            ]);

            return false;
        }

        // Update queue status
        $queueItem->update([
            'status' => 'approved',
            'processed_at' => now(),
            'processed_by' => 'system', // Could be user ID if needed
        ]);

        return true;
    }

    public function rejectCompany(string $cui): bool
    {
        // Find and delete the company
        $company = Company::where('cui', $cui)->first();

        if (! $company) {
            return false;
        }

        // Delete the company when rejected
        $company->delete();
        
        Log::info('Company rejected and deleted', [
            'cui' => $cui,
        ]);

        return true;
    }

    public function massProcessQueue(array $actions): array
    {
        $results = [];

        foreach ($actions as $cui => $action) {
            if ($action === 'approve') {
                $results[$cui] = $this->approveCompany($cui);
            } elseif ($action === 'reject') {
                $results[$cui] = $this->rejectCompany($cui);
            }
        }

        return $results;
    }

    private function fetchCompanyFromListaFirme(string $cui): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Laravel ANAF Client/1.0',
                ])
                ->get(self::LISTA_FIRME_URL, ['cui' => $cui]);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Lista-firme.info API response received', [
                    'cui' => $cui,
                    'has_data' => ! empty($data['name']),
                    'company_name' => $data['name'] ?? 'N/A',
                ]);

                return $data;
            }

            Log::warning('Lista-firme.info API request failed', [
                'cui' => $cui,
                'status' => $response->status(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::warning('Exception when calling Lista-firme.info API', [
                'cui' => $cui,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function fetchCompanyFromVIES(string $cui): ?array
    {
        try {
            // Rate limiting: 1 request per 2 seconds for VIES API
            $rateLimitKey = 'vies_api_rate_limit';
            $lastRequestTime = Cache::get($rateLimitKey);
            
            if ($lastRequestTime) {
                $timeSinceLastRequest = microtime(true) - $lastRequestTime;
                if ($timeSinceLastRequest < 2.0) {
                    $waitTime = 2.0 - $timeSinceLastRequest;
                    Log::info('VIES API rate limiting - waiting', [
                        'wait_time_seconds' => $waitTime,
                        'cui' => $cui
                    ]);
                    usleep((int)($waitTime * 1000000)); // Convert to microseconds
                }
            }
            
            // Store the current request time
            Cache::put($rateLimitKey, microtime(true), 60); // Cache for 1 minute
            
            // VIES API endpoint for VAT validation (GET method using new REST format)
            $viesUrl = "https://ec.europa.eu/taxation_customs/vies/rest-api/ms/RO/vat/{$cui}";

            Log::info('Making VIES API request', [
                'cui' => $cui,
                'url' => $viesUrl,
                'method' => 'GET'
            ]);

            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Laravel ANAF Client/1.0',
                ])
                ->get($viesUrl);

            Log::info('VIES API response received', [
                'cui' => $cui,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check if the VAT number is valid
                if (!empty($data['isValid']) && $data['isValid'] === true) {
                    Log::info('VIES API validation successful', [
                        'cui' => $cui,
                        'company_name' => $data['name'] ?? 'N/A',
                        'valid' => $data['isValid'],
                    ]);

                    // Transform VIES response to our standard format
                    return [
                        'name' => $data['name'] ?? 'Nume indisponibil',
                        'address' => $data['address'] ?? 'Adresa indisponibila',
                        'valid' => $data['isValid'],
                        'country_code' => $data['countryCode'] ?? 'RO',
                        'vat_number' => $data['vatNumber'] ?? $cui,
                        'request_date' => $data['requestDate'] ?? now()->toISOString(),
                        'data_source' => 'VIES-EU',
                    ];
                } else {
                    Log::info('VIES API response - company not valid', [
                        'cui' => $cui,
                        'is_valid' => $data['isValid'] ?? false,
                        'response_data' => $data,
                    ]);
                    return null;
                }
            }

            Log::warning('VIES API request failed', [
                'cui' => $cui,
                'status' => $response->status(),
                'error' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('VIES API request failed with exception', [
                'cui' => $cui,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function buildAddressFromAlternative(array $data): string
    {
        $addressParts = [];

        // Handle VIES format (simple address string)
        if (!empty($data['data_source']) && $data['data_source'] === 'VIES-EU') {
            if (!empty($data['address'])) {
                return $data['address'];
            }
            return 'Adresa nedeterminata';
        }

        // Handle Lista Firme format (structured address)
        if (! empty($data['address'])) {
            if (! empty($data['address']['street'])) {
                $addressParts[] = 'Str. '.$data['address']['street'];
            }
            if (! empty($data['address']['number'])) {
                $addressParts[] = 'Nr. '.$data['address']['number'];
            }
            if (! empty($data['address']['city'])) {
                $addressParts[] = $data['address']['city'];
            }
            if (! empty($data['address']['county'])) {
                $addressParts[] = 'Jud. '.$data['address']['county'];
            }
        }

        return implode(', ', array_filter($addressParts)) ?: 'Adresa nedeterminata';
    }

    private function enforceRateLimit(): void
    {
        $lastRequestTime = Cache::get(self::RATE_LIMIT_KEY);

        if ($lastRequestTime) {
            $timeSinceLastRequest = time() - $lastRequestTime;
            $waitTime = self::RATE_LIMIT_SECONDS - $timeSinceLastRequest;

            if ($waitTime > 0) {
                sleep($waitTime);
            }
        }

        Cache::put(self::RATE_LIMIT_KEY, time(), 60);
    }

    public function processNextPendingCompany(): array
    {
        // Priority processing for companies that need data fetching
        // Try different priority levels in sequence
        
        // Priority 1: Companies with 'Se încarcă...' (auto-approved from sync)
        $company = Company::where('denumire', 'Se încarcă...')
            ->whereNotIn('status', ['processing'])
            ->where('locked', '!=', true)
            ->orderBy('created_at', 'asc')
            ->first();
        
        // Priority 2: Approved companies with null or empty denumire 
        if (!$company) {
            $company = Company::where('status', 'approved')
                ->where(function($q) {
                    $q->whereNull('denumire')->orWhere('denumire', '');
                })
                ->whereNotIn('status', ['processing'])
                ->where('locked', '!=', true)
                ->orderBy('created_at', 'asc')
                ->first();
        }
        
        // Priority 3: Companies with pending_data status
        if (!$company) {
            $company = Company::where('status', 'pending_data')
                ->whereNotIn('status', ['processing'])
                ->where('locked', '!=', true)
                ->orderBy('created_at', 'asc')
                ->first();
        }

        if (!$company) {
            return [
                'success' => false,
                'message' => 'No companies need data processing',
                'processed_cui' => null
            ];
        }

        Log::info('🔄 Processing company with Targetare → ANAF → VIES cascade', [
            'cui' => $company->cui,
            'current_status' => $company->status,
            'current_denumire' => $company->denumire,
            'created_at' => $company->created_at
        ]);

        try {
            // Preserve approval status if already approved
            $wasApproved = $company->status === 'approved';
            $approvalData = [];
            if ($wasApproved) {
                $approvalData = [
                    'approved_at' => $company->approved_at,
                    'approved_by' => $company->approved_by,
                ];
            }

            // Update status to processing
            $company->update(['status' => 'processing']);
            
            // Enforce rate limiting - 2 seconds between API calls
            $this->enforceRateLimit();
            
            // Implement Targetare → ANAF → VIES cascade approach
            $companyData = null;
            $source = null;
            
            // 1. First try Targetare API (primary source)
            Log::info('🎯 Trying Targetare API first', ['cui' => $company->cui]);
            $companyData = $this->fetchCompanyFromTargetare($company->cui);
            if ($companyData && !empty($companyData['name'])) {
                $source = 'Targetare';
                Log::info('✅ Targetare API successful', [
                    'cui' => $company->cui,
                    'company_name' => $companyData['name']
                ]);
            }
            
            // 2. If Targetare fails, try Lista Firme (ANAF) API
            if (!$companyData || empty($companyData['name'])) {
                Log::info('🏢 Targetare failed, trying Lista Firme (ANAF) API', ['cui' => $company->cui]);
                $companyData = $this->fetchCompanyFromListaFirme($company->cui);
                if ($companyData && !empty($companyData['name'])) {
                    $source = 'ANAF';
                    Log::info('✅ Lista Firme (ANAF) API successful', [
                        'cui' => $company->cui,
                        'company_name' => $companyData['name']
                    ]);
                }
            }
            
            // 3. If both fail, try VIES as final fallback
            if (!$companyData || empty($companyData['name'])) {
                Log::info('🇪🇺 ANAF failed, trying VIES API as final fallback', ['cui' => $company->cui]);
                $viesData = $this->fetchCompanyFromVIES($company->cui);
                if ($viesData && !empty($viesData['name'])) {
                    $companyData = $viesData;
                    $source = 'VIES';
                    Log::info('✅ VIES fallback successful', [
                        'cui' => $company->cui,
                        'company_name' => $viesData['name']
                    ]);
                }
            }
            
            if ($companyData && !empty($companyData['name'])) {
                // Preserve approval status when updating
                $newStatus = $wasApproved ? 'approved' : 'active';
                
                // Prepare update data based on the source
                $updateData = [
                    'denumire' => $companyData['name'],
                    'adresa' => $this->buildAddressFromAlternative($companyData),
                    'data_source' => $companyData['data_source'] ?? $source,
                    'synced_at' => now(),
                    'status' => $newStatus,
                ];

                // Restore approval data if it was previously approved
                if ($wasApproved) {
                    $updateData = array_merge($updateData, $approvalData);
                }

                // Add source-specific fields
                if (!empty($companyData['data_source']) && $companyData['data_source'] === 'Targetare API') {
                    // Targetare-specific fields
                    $updateData = array_merge($updateData, [
                        'source_api' => 'targetare',
                        'tax_category' => $companyData['tax_category'] ?? null,
                        'company_status' => $companyData['company_status'] ?? null,
                        'county' => $companyData['county'] ?? null,
                        'locality' => $companyData['locality'] ?? null,
                        'street_nr' => $companyData['street_nr'] ?? null,
                        'street_name' => $companyData['street_name'] ?? null,
                        'postal_code' => $companyData['postal_code'] ?? null,
                        'full_address' => $companyData['full_address'] ?? null,
                        'company_id' => $companyData['company_id'] ?? null,
                        'founding_year' => $companyData['founding_year'] ?? null,
                        'split_vat' => $companyData['split_vat'] ?? null,
                        'checkout_vat' => $companyData['checkout_vat'] ?? null,
                        'vat' => $companyData['vat'] ?? null,
                        'caen_activities' => $companyData['caen_activities'] ?? [],
                        'company_type_targetare' => $companyData['company_type_targetare'] ?? null,
                        'has_email' => $companyData['has_email'] ?? null,
                        'has_phone' => $companyData['has_phone'] ?? null,
                        'has_verified_phone' => $companyData['has_verified_phone'] ?? null,
                        'has_administrator' => $companyData['has_administrator'] ?? null,
                        'has_website' => $companyData['has_website'] ?? null,
                        'has_fin_data' => $companyData['has_fin_data'] ?? null,
                        'employees_current' => $companyData['employees_current'] ?? null,
                        'targetare_synced_at' => $companyData['targetare_synced_at'] ?? null,
                    ]);
                } elseif (!empty($companyData['data_source']) && $companyData['data_source'] === 'VIES-EU') {
                    // VIES-specific fields
                    $updateData = array_merge($updateData, [
                        'source_api' => 'vies',
                        'country_code' => $companyData['country_code'] ?? 'RO',
                        'vat_number' => $companyData['vat_number'] ?? null,
                        'vat_valid' => $companyData['valid'] ?? false,
                        'vies_request_date' => $companyData['request_date'] ?? null,
                    ]);
                } else {
                    // Lista Firme specific fields
                    $updateData = array_merge($updateData, [
                        'source_api' => 'anaf',
                        'nrRegCom' => $companyData['reg_com'] ?? null,
                        'telefon' => $companyData['info']['phone'] ?? null,
                        'fax' => $companyData['info']['fax'] ?? null,
                        'codPostal' => $companyData['address']['postalCode'] ?? null,
                        'euid' => $companyData['euid'] ?? null,
                        'registration_date' => $companyData['registration_date'] ?? null,
                        'company_type' => $companyData['type'] ?? null,
                        'address_details' => $companyData['address'] ?? null,
                        'status_details' => $companyData['status'] ?? null,
                        'caen_codes' => $companyData['caen'] ?? [],
                        'full_address_info' => $companyData['info'] ?? null,
                        'registration_status' => $companyData['info']['registrationStatus'] ?? null,
                        'activity_code' => $companyData['info']['activityCode'] ?? null,
                        'bank_account' => $companyData['info']['bankAccount'] ?? null,
                        'ro_invoice_status' => $companyData['info']['roInvoiceStatus'] ?? null,
                        'authority_name' => $companyData['info']['authorityName'] ?? null,
                        'form_of_ownership' => $companyData['info']['formOfOwnership'] ?? null,
                        'organizational_form' => $companyData['info']['organizationalForm'] ?? null,
                        'legal_form' => $companyData['info']['legalForm'] ?? null,
                    ]);
                }

                // Update company with fetched data
                $company->update($updateData);
                
                Log::info('✅ Company data fetched successfully via cascade', [
                    'cui' => $company->cui,
                    'company_name' => $companyData['name'],
                    'source' => $source,
                    'was_approved' => $wasApproved
                ]);
            } else {
                // No valid data found from any source, mark as data_not_found but preserve approval
                $finalStatus = $wasApproved ? 'approved' : 'data_not_found';
                $updateData = [
                    'denumire' => 'Date indisponibile',
                    'status' => $finalStatus,
                    'synced_at' => now(),
                ];
                
                if ($wasApproved) {
                    $updateData = array_merge($updateData, $approvalData);
                }
                
                $company->update($updateData);
                
                Log::warning('⚠️ No valid data found from any source (Targetare → ANAF → VIES)', [
                    'cui' => $company->cui,
                    'preserved_approval' => $wasApproved
                ]);
            }

            return [
                'success' => true,
                'message' => 'Company processed successfully via cascade',
                'processed_cui' => $company->cui,
                'company_name' => $company->denumire,
                'source_used' => $source ?? 'none'
            ];

        } catch (\Exception $e) {
            // Update status to failed if there's an error, but preserve approval if it existed
            $errorStatus = ($company->status === 'approved' || $company->approved_at) ? 'approved' : 'failed';
            $errorUpdate = [
                'status' => $errorStatus,
                'denumire' => 'Eroare în procesare'
            ];
            
            if ($errorStatus === 'approved' && $company->approved_at) {
                $errorUpdate['approved_at'] = $company->approved_at;
                $errorUpdate['approved_by'] = $company->approved_by;
            }
            
            $company->update($errorUpdate);
            
            Log::error('❌ Failed to process company via cascade', [
                'cui' => $company->cui,
                'error' => $e->getMessage(),
                'preserved_approval' => $errorStatus === 'approved'
            ]);

            return [
                'success' => false,
                'message' => 'Failed to process company: ' . $e->getMessage(),
                'processed_cui' => $company->cui
            ];
        }
    }

    public function processSpecificCompany(string $cui): array
    {
        try {
            $company = Company::where('cui', $cui)->first();

            if (!$company) {
                return [
                    'success' => false,
                    'message' => 'Company not found with CUI: ' . $cui
                ];
            }

            // Store original approval status before processing
            $wasApproved = $company->status === 'approved';
            $approvalData = [];
            if ($wasApproved) {
                $approvalData = [
                    'approved_at' => $company->approved_at,
                    'approved_by' => $company->approved_by,
                ];
            }

            // Set status to processing to prevent other processes from picking it up
            $company->update(['status' => 'processing']);

            // Use the same logic as processNextPendingCompany but for this specific company
            Log::info('🔄 Processing specific company', ['cui' => $cui, 'was_approved' => $wasApproved]);

            // First try Targetare API (primary source)
            $companyData = $this->fetchCompanyFromTargetare($company->cui);
            
            // If Targetare fails, try Lista Firme API
            if (!$companyData || empty($companyData['name'])) {
                Log::info('Targetare failed, trying Lista Firme fallback', ['cui' => $company->cui]);
                $companyData = $this->fetchCompanyFromListaFirme($company->cui);
            }
            
            // If both Targetare and Lista Firme fail, try VIES as final fallback
            if (!$companyData || empty($companyData['name'])) {
                Log::info('Lista Firme failed, trying VIES fallback', ['cui' => $company->cui]);
                
                $viesData = $this->fetchCompanyFromVIES($company->cui);
                if ($viesData && !empty($viesData['name'])) {
                    $companyData = $viesData;
                    Log::info('✅ VIES fallback successful', [
                        'cui' => $company->cui,
                        'company_name' => $viesData['name']
                    ]);
                }
            }
            
            if ($companyData && !empty($companyData['name'])) {
                // Prepare update data based on the source
                // Preserve approval status if it was previously approved
                $newStatus = $wasApproved ? 'approved' : 'active';
                
                $updateData = [
                    'denumire' => $companyData['name'],
                    'adresa' => $this->buildAddressFromAlternative($companyData),
                    'data_source' => $companyData['data_source'] ?? 'Lista-firme.info',
                    'synced_at' => now(),
                    'status' => $newStatus,
                ];

                // Restore approval data if it was previously approved
                if ($wasApproved) {
                    $updateData = array_merge($updateData, $approvalData);
                }

                // Add source-specific fields
                if (!empty($companyData['data_source']) && $companyData['data_source'] === 'Targetare API') {
                    // Targetare-specific fields
                    $updateData = array_merge($updateData, [
                        'source_api' => 'targetare',
                        'tax_category' => $companyData['tax_category'] ?? null,
                        'company_status' => $companyData['company_status'] ?? null,
                        'county' => $companyData['county'] ?? null,
                        'locality' => $companyData['locality'] ?? null,
                        'street_nr' => $companyData['street_nr'] ?? null,
                        'street_name' => $companyData['street_name'] ?? null,
                        'postal_code' => $companyData['postal_code'] ?? null,
                        'full_address' => $companyData['full_address'] ?? null,
                        'company_id' => $companyData['company_id'] ?? null,
                        'founding_year' => $companyData['founding_year'] ?? null,
                        'split_vat' => $companyData['split_vat'] ?? null,
                        'checkout_vat' => $companyData['checkout_vat'] ?? null,
                        'vat' => $companyData['vat'] ?? null,
                        'caen_activities' => $companyData['caen_activities'] ?? [],
                        'company_type_targetare' => $companyData['company_type_targetare'] ?? null,
                        'has_email' => $companyData['has_email'] ?? null,
                        'has_phone' => $companyData['has_phone'] ?? null,
                        'has_verified_phone' => $companyData['has_verified_phone'] ?? null,
                        'has_administrator' => $companyData['has_administrator'] ?? null,
                        'has_website' => $companyData['has_website'] ?? null,
                        'has_fin_data' => $companyData['has_fin_data'] ?? null,
                        'employees_current' => $companyData['employees_current'] ?? null,
                        'targetare_synced_at' => $companyData['targetare_synced_at'] ?? null,
                    ]);
                } elseif (!empty($companyData['data_source']) && $companyData['data_source'] === 'VIES-EU') {
                    // VIES-specific fields
                    $updateData = array_merge($updateData, [
                        'source_api' => 'vies',
                        'country_code' => $companyData['country_code'] ?? 'RO',
                        'vat_number' => $companyData['vat_number'] ?? null,
                        'vat_valid' => $companyData['valid'] ?? false,
                        'vies_request_date' => $companyData['request_date'] ?? null,
                    ]);
                } else {
                    // Lista Firme specific fields
                    $updateData = array_merge($updateData, [
                        'euid' => $companyData['id'] ?? null,
                        'registration_date' => $companyData['date'] ?? null,
                        'company_type' => $companyData['type'] ?? null,
                        'address_details' => $companyData['address'] ?? null,
                        'status_details' => $companyData['status'] ?? null,
                        'caen_codes' => $companyData['caen'] ?? [],
                        'full_address_info' => $companyData['info'] ?? null,
                        'registration_status' => $companyData['info']['registrationStatus'] ?? null,
                        'activity_code' => $companyData['info']['activityCode'] ?? null,
                        'bank_account' => $companyData['info']['bankAccount'] ?? null,
                        'ro_invoice_status' => $companyData['info']['roInvoiceStatus'] ?? null,
                        'authority_name' => $companyData['info']['authorityName'] ?? null,
                        'form_of_ownership' => $companyData['info']['formOfOwnership'] ?? null,
                        'organizational_form' => $companyData['info']['organizationalForm'] ?? null,
                        'legal_form' => $companyData['info']['legalForm'] ?? null,
                    ]);
                }

                // Update company with fetched data
                $company->update($updateData);
                
                Log::info('✅ Company verification successful', [
                    'cui' => $company->cui,
                    'company_name' => $companyData['name']
                ]);

                return [
                    'success' => true,
                    'message' => 'Company verification successful',
                    'cui' => $company->cui,
                    'company_name' => $company->denumire
                ];
            } else {
                // No valid data found, mark as data_not_found
                $company->update([
                    'denumire' => 'Date indisponibile',
                    'status' => 'data_not_found',
                    'synced_at' => now(),
                ]);
                
                Log::warning('⚠️ No valid data found for company verification', ['cui' => $company->cui]);

                return [
                    'success' => false,
                    'message' => 'No data found for company',
                    'cui' => $company->cui
                ];
            }

        } catch (\Exception $e) {
            // Update status to failed if there's an error
            if (isset($company)) {
                $company->update([
                    'status' => 'failed',
                    'denumire' => 'Eroare în procesare'
                ]);
            }
            
            Log::error('❌ Failed to verify company', [
                'cui' => $cui,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to verify company: ' . $e->getMessage(),
                'cui' => $cui
            ];
        }
    }

    /**
     * Fetch company data from Targetare API and format it
     */
    private function fetchCompanyFromTargetare(string $cui): ?array
    {
        try {
            // Use combined method to avoid duplicate API calls
            $result = $this->targetareService->getCompanyWithFinancialData($cui);
            
            if (!$result['success'] || !$result['company_data']) {
                Log::info('Targetare API failed for CUI', [
                    'cui' => $cui,
                    'error' => $result['error'] ?? 'No data returned'
                ]);
                return null;
            }

            $data = $result['company_data'];
            $financialData = $result['financial_data'];

            Log::info('✅ Targetare API successful', [
                'cui' => $cui,
                'company_name' => $data['companyName'] ?? 'N/A',
                'remaining_requests' => $result['remaining_requests'] ?? null
            ]);

            // Map Targetare data to our standard format
            return [
                'name' => $data['companyName'] ?? '',
                'address' => $this->buildAddressFromTargetare($data),
                'data_source' => 'Targetare API',
                'source_api' => 'targetare',
                
                // Targetare specific data
                'tax_category' => $data['taxCategory'] ?? null,
                'company_status' => $data['status'] ?? null,
                'county' => $data['county'] ?? null,
                'locality' => $data['locality'] ?? null,
                'street_nr' => $data['streetNr'] ?? null,
                'street_name' => $data['streetName'] ?? null,
                'postal_code' => $data['postalCode'] ?? null,
                'full_address' => $data['fullAddress'] ?? null,
                'company_id' => $data['companyId'] ?? null,
                'founding_year' => $data['foundingYear'] ?? null,
                'split_vat' => $data['splitVAT'] ?? null,
                'checkout_vat' => $data['checkoutVAT'] ?? null,
                'vat' => $data['VAT'] ?? null,
                'caen_activities' => $data['caen'] ?? [],
                'company_type_targetare' => $data['companyType'] ?? null,
                'has_email' => $data['hasEmail'] ?? null,
                'has_phone' => $data['hasPhone'] ?? null,
                'has_verified_phone' => $data['hasVerifiedPhone'] ?? null,
                'has_administrator' => $data['hasAdministrator'] ?? null,
                'has_website' => $data['hasWebsite'] ?? null,
                'has_fin_data' => $data['hasFinData'] ?? null,
                
                // Employee count from financial data
                'employees_current' => $financialData['employee'] ?? null,
                'targetare_synced_at' => now(),
            ];

        } catch (\Exception $e) {
            Log::error('Targetare API error', [
                'cui' => $cui,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Build address string from Targetare data
     */
    private function buildAddressFromTargetare(array $data): string
    {
        $addressParts = [];
        
        if (!empty($data['fullAddress'])) {
            return $data['fullAddress'];
        }
        
        // Build address from components
        if (!empty($data['streetName'])) {
            $street = $data['streetName'];
            if (!empty($data['streetNr'])) {
                $street .= ', Nr.' . $data['streetNr'];
            }
            $addressParts[] = $street;
        }
        
        if (!empty($data['locality'])) {
            $addressParts[] = $data['locality'];
        }
        
        if (!empty($data['county'])) {
            $addressParts[] = 'Jud. ' . $data['county'];
        }
        
        if (!empty($data['postalCode'])) {
            $addressParts[] = 'CP ' . $data['postalCode'];
        }
        
        return implode(', ', $addressParts) ?: 'Adresă necunoscută';
    }
}
