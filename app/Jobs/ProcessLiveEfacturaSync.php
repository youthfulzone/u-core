<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\EfacturaInvoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessLiveEfacturaSync
{
    public $timeout = 600; // 10 minutes timeout

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $syncId,
        public array $companyIds,
        public string $accessToken
    ) {
        // Remove queue implementation - run synchronously
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('ProcessLiveEfacturaSync started', [
            'sync_id' => $this->syncId,
            'total_companies' => count($this->companyIds),
            'company_ids' => $this->companyIds,
            'has_access_token' => !empty($this->accessToken)
        ]);

        $totalCompanies = count($this->companyIds);
        $totalProcessed = 0;
        $currentCompanyIndex = 0;

        if (empty($this->companyIds)) {
            Log::error('No company IDs provided to sync job');
            return;
        }

        if (empty($this->accessToken)) {
            Log::error('No access token provided to sync job');
            return;
        }

        // Initial delay to allow frontend to start monitoring
        Log::info('Waiting 3 seconds for frontend to start monitoring...');
        $this->updateSyncStatus(0, $totalCompanies, 0, 'Se pregătește...', 'Așteptare pentru monitorizare în timp real...');
        sleep(3);

        try {
            foreach ($this->companyIds as $cui) {
                $currentCompanyIndex++;

                // Get company details
                $company = Company::where('cui', $cui)->first();
                if (!$company) {
                    Log::warning("Company not found for CUI: {$cui}");
                    continue;
                }

                // Update status
                $this->updateSyncStatus($currentCompanyIndex, $totalCompanies, $totalProcessed, $company->denumire, 'Se procesează...');

                try {
                    // Make API call to ANAF for this specific company
                    $response = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Accept' => 'application/json'
                    ])->timeout(30)->get('https://api.anaf.ro/prod/FCTEL/rest/listaMesajeFactura', [
                        'zile' => 30,
                        'cif' => $cui
                    ]);

                    if ($response->successful()) {
                        $data = $response->json();

                        if (isset($data['mesaje']) && is_array($data['mesaje'])) {
                            $invoiceCount = count($data['mesaje']);
                            Log::info("Processing {$invoiceCount} invoices for company: {$company->denumire} (CUI: {$cui})");

                            // Update status to show total invoices found for this company
                            $this->updateSyncStatus(
                                $currentCompanyIndex,
                                $totalCompanies,
                                $totalProcessed,
                                $company->denumire,
                                "Se vor descărca {$invoiceCount} facturi"
                            );

                            // Brief delay to show the count
                            sleep(2);

                            // Process each invoice with a 1-second delay - one by one for real-time updates
                            foreach ($data['mesaje'] as $index => $message) {
                                try {
                                    // Update status BEFORE creating invoice to show what's happening
                                    $invoiceNumber = $message['nr_factura'] ?? 'N/A';
                                    $this->updateSyncStatus(
                                        $currentCompanyIndex,
                                        $totalCompanies,
                                        $totalProcessed,
                                        $company->denumire,
                                        "Se procesează factura #{$invoiceNumber} (" . ($index + 1) . " din {$invoiceCount})"
                                    );

                                    // 1-second delay BEFORE creating to show the processing message
                                    sleep(1);

                                    // Create invoice record
                                    $invoice = EfacturaInvoice::create([
                                        'cui' => $cui,
                                        'download_id' => $message['id'] ?? uniqid(),
                                        'message_type' => $message['tip'] ?? 'unknown',
                                        'invoice_number' => $message['nr_factura'] ?? null,
                                        'invoice_date' => isset($message['data_factura']) ? Carbon::parse($message['data_factura'])->format('Y-m-d') : null,
                                        'supplier_name' => $message['denumire_furnizor'] ?? $company->denumire,
                                        'customer_name' => $message['denumire_client'] ?? 'N/A',
                                        'total_amount' => $message['suma'] ?? 0,
                                        'currency' => $message['moneda'] ?? 'RON',
                                        'status' => 'synced',
                                        'download_status' => 'downloaded',
                                        'downloaded_at' => now(),
                                        'xml_content' => json_encode($message),
                                        'has_pdf' => false,
                                        'has_errors' => false
                                    ]);

                                    $totalProcessed++;

                                    // Update status AFTER creating invoice
                                    $this->updateSyncStatus(
                                        $currentCompanyIndex,
                                        $totalCompanies,
                                        $totalProcessed,
                                        $company->denumire,
                                        "Descărcată factura #{$invoiceNumber} ✓ ({$totalProcessed} total)"
                                    );

                                    Log::info("Invoice created: {$invoiceNumber} for {$company->denumire}");

                                } catch (\Exception $e) {
                                    Log::error("Error creating invoice for CUI {$cui}: " . $e->getMessage());
                                }
                            }
                        } else {
                            Log::info("No invoices found for company: {$company->denumire} (CUI: {$cui})");
                            // Update status even when no invoices found
                            $this->updateSyncStatus(
                                $currentCompanyIndex,
                                $totalCompanies,
                                $totalProcessed,
                                $company->denumire,
                                "Nu s-au găsit facturi"
                            );
                        }
                    } else {
                        Log::error("API call failed for CUI {$cui}: " . $response->body());
                    }

                    // Longer pause between companies for better visibility
                    sleep(3);

                } catch (\Exception $e) {
                    Log::error("Error processing company CUI {$cui}: " . $e->getMessage());

                    // Update status with error but continue
                    $this->updateSyncStatus(
                        $currentCompanyIndex,
                        $totalCompanies,
                        $totalProcessed,
                        $company->denumire,
                        'Eroare: ' . $e->getMessage()
                    );
                }
            }

            // Mark sync as completed
            $this->updateSyncStatus($totalCompanies, $totalCompanies, $totalProcessed, 'Toate companiile', 'Sincronizare finalizată cu succes', 'completed');

            Log::info('ProcessLiveEfacturaSync completed successfully', [
                'sync_id' => $this->syncId,
                'total_processed' => $totalProcessed,
                'total_companies' => $totalCompanies
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessLiveEfacturaSync failed', [
                'sync_id' => $this->syncId,
                'error' => $e->getMessage()
            ]);

            // Mark sync as failed
            $this->updateSyncStatus($currentCompanyIndex, $totalCompanies, $totalProcessed, 'Eroare', $e->getMessage(), 'failed');
        } finally {
            // Clean up flags
            Cache::put('really_simple_sync_active', false);
            Cache::put('really_simple_sync_status', 'Idle');
        }
    }

    private function updateSyncStatus(int $currentCompany, int $totalCompanies, int $totalProcessed, string $companyName, string $message, string $status = 'running'): void
    {
        $statusData = [
            'is_syncing' => $status === 'running',
            'status' => $status,
            'sync_id' => $this->syncId,
            'current_company' => $currentCompany,
            'total_companies' => $totalCompanies,
            'total_processed' => $totalProcessed,
            'company_name' => $companyName,
            'message' => $message,
            'last_updated' => now()->toISOString()
        ];

        // Log the status update for debugging
        Log::info('Updating sync status', [
            'sync_id' => $this->syncId,
            'company' => $companyName,
            'progress' => "{$currentCompany}/{$totalCompanies}",
            'processed' => $totalProcessed,
            'message' => $message,
            'status' => $status
        ]);

        // Update both specific and general status caches
        Cache::put("efactura_sync_status_{$this->syncId}", $statusData, 3600); // 1 hour TTL
        Cache::put('efactura_sync_status', $statusData, 3600);

        // Also update simple status for compatibility
        Cache::put('really_simple_sync_status', $message);
        Cache::put('really_simple_sync_active', $status === 'running');
    }
}
