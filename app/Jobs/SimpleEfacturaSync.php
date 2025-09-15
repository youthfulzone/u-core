<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\EfacturaInvoice;
use App\Services\AnafEfacturaService;
use App\Services\AnafRateLimiter;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SimpleEfacturaSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $syncId,
        private int $days = 30
    ) {}

    public function handle(): void
    {
        Log::info('Starting simple e-factura sync', ['sync_id' => $this->syncId]);

        // Set simple status
        $this->setStatus('running', 'Downloading invoices...');

        $efacturaService = new AnafEfacturaService();
        $rateLimiter = new AnafRateLimiter();

        $totalProcessed = 0;
        $totalErrors = 0;

        try {
            // Get all companies with valid CUIs
            $companies = Company::whereNotNull('cui')
                ->where('cui', '!=', '')
                ->get(['cui', 'denumire']);

            $validCompanies = [];
            foreach ($companies as $company) {
                if (preg_match('/^[0-9]{6,10}$/', $company->cui)) {
                    $validCompanies[] = [
                        'cui' => $company->cui,
                        'name' => $company->denumire
                    ];
                }
            }

            if (empty($validCompanies)) {
                $this->setStatus('completed', 'No valid companies found');
                return;
            }

            $endDate = Carbon::now();
            $startDate = Carbon::now()->subDays($this->days);

            // Process each company atomically (one by one)
            foreach ($validCompanies as $index => $companyInfo) {
                $cui = $companyInfo['cui'];
                $companyName = $companyInfo['name'];
                $currentCompany = $index + 1;
                $totalCompanies = count($validCompanies);

                Log::info("Processing company {$currentCompany}/{$totalCompanies}: {$cui} - {$companyName}");
                $this->setStatus('running', "Processing {$companyName} ({$cui})", $currentCompany, $totalCompanies);

                try {
                    // Get messages for this company
                    $messages = $efacturaService->getAllMessagesPaginated($cui, $startDate, $endDate);

                    if (empty($messages)) {
                        continue;
                    }

                    // Get existing invoice IDs for this company (batch check)
                    $downloadIds = array_filter(array_column($messages, 'download_id'));
                    $existingIds = EfacturaInvoice::whereIn('download_id', $downloadIds)
                        ->pluck('download_id')
                        ->toArray();

                    // Process each message for this company
                    $companyInvoicesProcessed = 0;
                    $totalInvoicesForCompany = count($messages) - count($existingIds);

                    foreach ($messages as $messageIndex => $message) {
                        $downloadId = $message['download_id'] ?? null;
                        if (!$downloadId) continue;

                        // Skip if already exists
                        if (in_array($downloadId, $existingIds)) {
                            $totalProcessed++;
                            continue;
                        }

                        try {
                            // Update status for this invoice
                            $this->setStatus('running', "Downloading: {$companyName} ({$currentCompany}/{$totalCompanies}) - {$companyInvoicesProcessed} invoices processed", $currentCompany, $totalCompanies);

                            // Download invoice
                            $downloadData = $efacturaService->downloadMessage($downloadId, $message);
                            $invoiceData = $downloadData['invoice_data'];

                            // Create invoice record
                            EfacturaInvoice::create([
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'message_type' => $message['tip'] ?? 'UNKNOWN',
                                'invoice_number' => $invoiceData['invoice_number'] ?? null,
                                'invoice_date' => $invoiceData['issue_date'] ?? null,
                                'supplier_name' => $invoiceData['supplier_name'] ?? null,
                                'supplier_tax_id' => $invoiceData['supplier_tax_id'] ?? $message['cif_emitent'] ?? null,
                                'customer_name' => $invoiceData['customer_name'] ?? null,
                                'customer_tax_id' => $invoiceData['customer_tax_id'] ?? $message['cif_beneficiar'] ?? null,
                                'total_amount' => $invoiceData['total_amount'] ?? 0,
                                'currency' => $invoiceData['currency'] ?? 'RON',
                                'xml_content' => $downloadData['xml_content'],
                                'xml_signature' => $downloadData['xml_signature'],
                                'xml_errors' => $downloadData['xml_errors'],
                                'zip_content' => $downloadData['zip_content'],
                                'status' => $invoiceData['status'] ?? 'synced',
                                'download_status' => 'downloaded',
                                'downloaded_at' => $downloadData['downloaded_at'],
                                'archived_at' => now(),
                                'file_size' => $downloadData['file_size'],
                                'sync_id' => $this->syncId,
                            ]);

                            $totalProcessed++;
                            $companyInvoicesProcessed++;

                            // Rate limiting
                            $rateLimiter->waitForNextCall();

                        } catch (\Exception $e) {
                            $totalErrors++;
                            Log::error('Failed to process invoice', [
                                'download_id' => $downloadId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    Log::info("Completed company {$currentCompany}/{$totalCompanies}: {$companyName} - {$companyInvoicesProcessed} invoices processed");

                } catch (\Exception $e) {
                    $totalErrors++;
                    Log::error('Failed to process company', [
                        'cui' => $cui,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->setStatus('completed', "Completed: {$totalProcessed} invoices processed, {$totalErrors} errors");
            Log::info('Simple sync completed', [
                'sync_id' => $this->syncId,
                'processed' => $totalProcessed,
                'errors' => $totalErrors
            ]);

        } catch (\Exception $e) {
            $this->setStatus('failed', 'Sync failed: ' . $e->getMessage());
            Log::error('Simple sync failed', [
                'sync_id' => $this->syncId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function setStatus(string $status, string $message, ?int $currentCompany = null, ?int $totalCompanies = null): void
    {
        $statusData = [
            'sync_id' => $this->syncId,
            'status' => $status,
            'is_syncing' => $status === 'running',
            'message' => $message,
            'last_update' => now()->toISOString()
        ];

        if ($currentCompany !== null && $totalCompanies !== null) {
            $statusData['current_company'] = $currentCompany;
            $statusData['total_companies'] = $totalCompanies;
        }

        cache()->put('efactura_sync_status', $statusData, 3600);
        cache()->put("efactura_sync_status_{$this->syncId}", $statusData, 3600);
    }
}