<?php

namespace App\Jobs;

use App\Models\AnafCredential;
use App\Models\EfacturaInvoice;
use App\Services\AnafEfacturaService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SyncEfacturaMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 3600; // 1 hour timeout

    private int $days;
    private ?string $filter;
    private ?string $specificCui;

    /**
     * Create a new job instance.
     */
    public function __construct(int $days = 30, ?string $filter = null, ?string $specificCui = null)
    {
        $this->days = $days;
        $this->filter = $filter;
        $this->specificCui = $specificCui;
    }

    /**
     * Execute the job.
     */
    public function handle(AnafEfacturaService $efacturaService): void
    {
        Log::info('Background E-factura sync job started', [
            'days' => $this->days,
            'filter' => $this->filter,
            'specific_cui' => $this->specificCui,
            'timestamp' => now()
        ]);

        try {
            $credential = AnafCredential::active()->first();
            if (!$credential) {
                Log::error('No active ANAF credentials found for background sync');
                return;
            }

            $endDate = Carbon::now();
            $startDate = Carbon::now()->subDays($this->days);

            // Get list of CUIs to sync
            $cuisToSync = [];
            
            if ($this->specificCui) {
                $cuisToSync[] = $this->specificCui;
            } else {
                $companies = \App\Models\Company::whereNotNull('cui')
                    ->where('cui', '!=', '')
                    ->get(['cui', 'denumire']);
                
                foreach ($companies as $company) {
                    if (preg_match('/^[0-9]{6,10}$/', $company->cui)) {
                        $cuisToSync[] = [
                            'cui' => $company->cui,
                            'name' => $company->denumire
                        ];
                    }
                }
            }

            if (empty($cuisToSync)) {
                Log::warning('No valid CUIs found for background sync');
                return;
            }

            $results = [
                'total_cuis' => count($cuisToSync),
                'total_synced' => 0,
                'total_errors' => 0,
                'total_messages' => 0
            ];

            // Set initial status
            Cache::put('efactura_sync_status', [
                'is_syncing' => true,
                'current_cui' => null,
                'current_company' => 'Initializing...',
                'current_invoice' => null,
                'total_invoices' => 0,
                'processed_invoices' => 0,
                'status' => 'starting',
                'last_error' => null,
                'started_at' => now()->toISOString()
            ], 3600);

            // Process each CUI
            foreach ($cuisToSync as $cuiInfo) {
                $cui = is_array($cuiInfo) ? $cuiInfo['cui'] : $cuiInfo;
                $companyName = is_array($cuiInfo) ? $cuiInfo['name'] : $cui;
                
                Log::info('Processing CUI in background', ['cui' => $cui, 'company' => $companyName]);
                
                try {
                    $messages = $efacturaService->getAllMessagesPaginated($cui, $startDate, $endDate, $this->filter);
                    
                    $results['total_messages'] += count($messages);
                    
                    // Update total count in cache
                    Cache::put('efactura_sync_status', [
                        'is_syncing' => true,
                        'current_cui' => $cui,
                        'current_company' => $companyName,
                        'current_invoice' => 'Fetching messages...',
                        'total_invoices' => $results['total_messages'],
                        'processed_invoices' => $results['total_synced'],
                        'status' => 'processing',
                        'last_error' => null,
                        'started_at' => Cache::get('efactura_sync_status.started_at')
                    ], 3600);

                    $cuiSyncedCount = 0;
                    $cuiErrorCount = 0;

                    foreach ($messages as $messageIndex => $message) {
                        try {
                            // Add 10 second delay between each invoice download for monitoring
                            if ($messageIndex > 0) {
                                sleep(10);
                            }
                            
                            $downloadId = $message['id'] ?? $message['id_descarcare'] ?? null;
                            
                            // Update cache for current invoice
                            Cache::put('efactura_sync_status', [
                                'is_syncing' => true,
                                'current_cui' => $cui,
                                'current_company' => $companyName,
                                'current_invoice' => $message['nr_factura'] ?? "Factura",
                                'total_invoices' => $results['total_messages'],
                                'processed_invoices' => $results['total_synced'] + $messageIndex + 1,
                                'status' => 'processing',
                                'last_error' => null,
                                'started_at' => Cache::get('efactura_sync_status.started_at')
                            ], 3600);
                            
                            if (!$downloadId) {
                                Log::warning('Message missing download ID in background sync', [
                                    'cui' => $cui,
                                    'message' => $message
                                ]);
                                $cuiErrorCount++;
                                continue;
                            }
                            
                            // Check if already exists
                            $existing = EfacturaInvoice::where('download_id', $downloadId)->first();
                            if ($existing) {
                                continue;
                            }

                            // Download and store
                            $downloadData = $efacturaService->downloadMessage($downloadId, $message);
                            $invoiceData = $downloadData['invoice_data'];

                            EfacturaInvoice::create([
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'message_type' => $message['tip'],
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
                                'file_size' => $downloadData['file_size']
                            ]);

                            $cuiSyncedCount++;

                        } catch (\Exception $e) {
                            Log::error('Failed to sync message in background job - continuing', [
                                'cui' => $cui,
                                'message_id' => $downloadId ?? 'unknown',
                                'error' => $e->getMessage(),
                                'message_index' => $messageIndex
                            ]);
                            
                            $cuiErrorCount++;
                            
                            // Show error in cache but continue
                            Cache::put('efactura_sync_status', [
                                'is_syncing' => true,
                                'current_cui' => $cui,
                                'current_company' => $companyName,
                                'current_invoice' => 'ERROR - ' . ($message['nr_factura'] ?? "Factura"),
                                'total_invoices' => $results['total_messages'],
                                'processed_invoices' => $results['total_synced'] + $messageIndex + 1,
                                'status' => 'processing',
                                'last_error' => substr($e->getMessage(), 0, 100) . '...',
                                'started_at' => Cache::get('efactura_sync_status.started_at')
                            ], 3600);
                            
                            continue;
                        }
                    }

                    $results['total_synced'] += $cuiSyncedCount;
                    $results['total_errors'] += $cuiErrorCount;

                } catch (\Exception $e) {
                    Log::error('Failed to sync CUI in background job', [
                        'cui' => $cui,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Mark as completed and keep for longer
            Cache::put('efactura_sync_status', [
                'is_syncing' => false,
                'current_cui' => null,
                'current_company' => 'Sincronizare finalizată',
                'current_invoice' => 'Complet',
                'total_invoices' => $results['total_messages'],
                'processed_invoices' => $results['total_synced'],
                'status' => 'completed',
                'last_error' => null,
                'completed_at' => now()->toISOString(),
                'started_at' => Cache::get('efactura_sync_status.started_at')
            ], 1800); // Keep completed status for 30 minutes

            Log::info('Background E-factura sync job completed', $results);

        } catch (\Exception $e) {
            Log::error('Background E-factura sync job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Mark as failed and keep visible
            Cache::put('efactura_sync_status', [
                'is_syncing' => false,
                'current_cui' => null,
                'current_company' => 'Eroare sincronizare',
                'current_invoice' => 'Eșuat',
                'total_invoices' => 0,
                'processed_invoices' => 0,
                'status' => 'failed',
                'last_error' => substr($e->getMessage(), 0, 200),
                'failed_at' => now()->toISOString()
            ], 1800); // Keep failed status for 30 minutes
        }
    }
}
