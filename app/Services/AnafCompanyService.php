<?php

namespace App\Services;

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
                continue;
            }

            // Check if CUI already in queue
            if (CompanyQueue::where('cui', $cui)->exists()) {
                continue;
            }

            // Add to queue
            CompanyQueue::create([
                'cui' => $cui,
                'status' => 'pending',
            ]);

            $queuedCount++;
        }

        Log::info('Companies queued for processing', [
            'queued_count' => $queuedCount,
            'total_cuis' => count($cuis),
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
        $queueItem = CompanyQueue::where('cui', $cui)->first();

        if (! $queueItem) {
            return false;
        }

        $queueItem->update([
            'status' => 'rejected',
            'processed_at' => now(),
            'processed_by' => 'system',
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

    private function buildAddressFromAlternative(array $data): string
    {
        $addressParts = [];

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
}
