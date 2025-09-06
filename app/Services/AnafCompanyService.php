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

            // Immediately save company with just the CUI
            Company::create([
                'cui' => $cui,
                'denumire' => null, // Will be populated by background fetch
                'status' => 'pending_data', // Indicates data needs to be fetched
                'synced_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $queuedCount++;
        }

        Log::info('Companies registered for processing', [
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
        // First priority: Get companies that have no data fetched (null denumire) regardless of status
        // Process from first (top) to last - oldest first, but skip locked companies
        $company = Company::whereNull('denumire')
            ->whereNotIn('status', ['processing']) // Don't pick up companies currently being processed
            ->where('locked', '!=', true) // Skip locked companies
            ->orderBy('created_at', 'asc') // Process from first (top) to last
            ->first();

        // Second priority: If no companies without names, look for pending_data status (also skip locked)
        if (!$company) {
            $company = Company::where('status', 'pending_data')
                ->where('locked', '!=', true) // Skip locked companies
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

        Log::info('🔄 Processing company (prioritizing unfetched data)', [
            'cui' => $company->cui,
            'current_status' => $company->status,
            'has_denumire' => !is_null($company->denumire),
            'created_at' => $company->created_at
        ]);

        try {
            // Update status to processing
            $company->update(['status' => 'processing']);
            
            // Enforce rate limiting
            $this->enforceRateLimit();
            
            // Fetch company data from Lista Firme API first
            $companyData = $this->fetchCompanyFromListaFirme($company->cui);
            
            // If Lista Firme doesn't have the company, try VIES as fallback
            if (!$companyData || empty($companyData['name'])) {
                Log::info('🔄 Lista Firme failed, trying VIES API as fallback', [
                    'cui' => $company->cui
                ]);
                
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
                $updateData = [
                    'denumire' => $companyData['name'],
                    'adresa' => $this->buildAddressFromAlternative($companyData),
                    'data_source' => $companyData['data_source'] ?? 'Lista-firme.info',
                    'synced_at' => now(),
                    'status' => 'active',
                ];

                // Add source-specific fields
                if (!empty($companyData['data_source']) && $companyData['data_source'] === 'VIES-EU') {
                    // VIES-specific fields
                    $updateData = array_merge($updateData, [
                        'country_code' => $companyData['country_code'] ?? 'RO',
                        'vat_number' => $companyData['vat_number'] ?? null,
                        'vat_valid' => $companyData['valid'] ?? false,
                        'vies_request_date' => $companyData['request_date'] ?? null,
                    ]);
                } else {
                    // Lista Firme specific fields
                    $updateData = array_merge($updateData, [
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
                
                Log::info('✅ Company data fetched successfully', [
                    'cui' => $company->cui,
                    'company_name' => $companyData['name']
                ]);
            } else {
                // No valid data found, mark as data_not_found
                $company->update([
                    'denumire' => 'Date indisponibile',
                    'status' => 'data_not_found',
                    'synced_at' => now(),
                ]);
                
                Log::warning('⚠️ No valid data found for company', ['cui' => $company->cui]);
            }

            return [
                'success' => true,
                'message' => 'Company processed successfully',
                'processed_cui' => $company->cui,
                'company_name' => $company->denumire
            ];

        } catch (\Exception $e) {
            // Update status to failed if there's an error
            $company->update([
                'status' => 'failed',
                'denumire' => 'Eroare în procesare'
            ]);
            
            Log::error('❌ Failed to process company', [
                'cui' => $company->cui,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to process company: ' . $e->getMessage(),
                'processed_cui' => $company->cui
            ];
        }
    }
}
