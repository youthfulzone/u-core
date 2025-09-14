<?php

namespace App\Jobs;

use App\Models\AnafCredential;
use App\Models\EfacturaInvoice;
use App\Services\AnafEfacturaService;
use App\Services\AnafRateLimiter;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEfacturaSequential implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 3600; // 1 hour timeout
    public int $tries = 1;
    public int $backoff = 60;

    private ?string $lastError = null;
    private array $errorDetails = [
        'api_errors' => [],
        'db_errors' => [],
        'other_errors' => []
    ];

    public function __construct(
        public string $syncId,
        public array $companies, // All companies sorted by CUI
        public Carbon $startDate,
        public Carbon $endDate,
        public ?string $filter = null,
        public bool $testMode = false
    ) {
        $this->onQueue('efactura-sync');
    }

    /**
     * Execute the job - process companies sequentially
     */
    public function handle(): void
    {
        // Set unlimited execution time for this job
        set_time_limit(0);
        ini_set('max_execution_time', 0);

        $efacturaService = new AnafEfacturaService();
        $rateLimiter = new AnafRateLimiter();

        Log::info('Starting sequential e-factura sync', [
            'sync_id' => $this->syncId,
            'total_companies' => count($this->companies),
            'test_mode' => $this->testMode
        ]);

        $totalProcessed = 0;
        $totalErrors = 0;

        try {
            // Update initial sync status
            $this->updateSyncStatus('processing', 0, 0, 0, count($this->companies));

            foreach ($this->companies as $companyIndex => $companyInfo) {
                $cui = $companyInfo['cui'];
                $companyName = $companyInfo['name'];
                $currentCompany = $companyIndex + 1;

                Log::info('Processing company', [
                    'sync_id' => $this->syncId,
                    'company' => $currentCompany,
                    'total_companies' => count($this->companies),
                    'cui' => $cui,
                    'company_name' => $companyName
                ]);


                // Update status for new company
                $this->updateSyncStatus('processing', $currentCompany, 0, 0, count($this->companies), $cui, $companyName);

                // Get messages for this company
                $messages = $efacturaService->getAllMessagesPaginated(
                    $cui,
                    $this->startDate,
                    $this->endDate,
                    $this->filter
                );

                Log::info('Messages retrieved for company', [
                    'sync_id' => $this->syncId,
                    'cui' => $cui,
                    'total_messages' => count($messages)
                ]);

                if (empty($messages)) {
                    Log::info('No messages found for company, skipping', [
                        'cui' => $cui,
                        'company_name' => $companyName
                    ]);
                    continue;
                }

                // Process each message for this company
                foreach ($messages as $messageIndex => $message) {
                    $currentInvoice = $messageIndex + 1;
                    $downloadId = $message['id'] ?? $message['id_descarcare'] ?? null;

                    if (!$downloadId) {
                        $totalErrors++;
                        continue;
                    }


                    // Update status for current invoice
                    $invoiceIdentifier = $message['nr_factura'] ?? "Invoice #{$currentInvoice}";
                    $this->updateSyncStatus('processing', $currentCompany, $currentInvoice, count($messages), count($this->companies), $cui, $companyName, $invoiceIdentifier);

                    Log::info('Processing invoice', [
                        'sync_id' => $this->syncId,
                        'company' => "{$currentCompany}/" . count($this->companies),
                        'invoice' => "{$currentInvoice}/" . count($messages),
                        'cui' => $cui,
                        'download_id' => $downloadId,
                        'invoice_id' => $invoiceIdentifier
                    ]);

                    try {
                        // Check if already exists
                        if (EfacturaInvoice::where('download_id', $downloadId)->exists()) {
                            Log::info('Invoice already exists, skipping', [
                                'download_id' => $downloadId,
                                'invoice_id' => $invoiceIdentifier
                            ]);
                            continue;
                        }

                        // Download and store
                        $downloadData = $efacturaService->downloadMessage($downloadId, $message);
                        $invoiceData = $downloadData['invoice_data'];

                        // Create invoice record
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
                            'file_size' => $downloadData['file_size'],
                            'sync_id' => $this->syncId,
                            'pdf_content' => null,
                            'pdf_generated_at' => null
                        ]);

                        $totalProcessed++;

                        Log::info('Invoice processed successfully', [
                            'sync_id' => $this->syncId,
                            'download_id' => $downloadId,
                            'total_processed' => $totalProcessed
                        ]);

                        // ANAF API Rate Limiting
                        $rateLimiter->waitForNextCall();

                    } catch (\Exception $e) {
                        $errorMessage = $e->getMessage();

                        Log::error('Error processing invoice', [
                            'sync_id' => $this->syncId,
                            'cui' => $cui,
                            'download_id' => $downloadId,
                            'error' => $errorMessage,
                            'error_type' => get_class($e)
                        ]);

                        $totalErrors++;
                        $this->lastError = $errorMessage;

                        // Categorize error
                        if (str_contains($errorMessage, 'Transaction numbers') || str_contains($errorMessage, 'MongoDB')) {
                            $this->errorDetails['db_errors'][] = [
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'error' => $errorMessage
                            ];
                        } elseif (str_contains($errorMessage, 'ANAF') || str_contains($errorMessage, 'API') || str_contains($errorMessage, 'rate limit')) {
                            $this->errorDetails['api_errors'][] = [
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'error' => $errorMessage
                            ];
                        } else {
                            $this->errorDetails['other_errors'][] = [
                                'cui' => $cui,
                                'download_id' => $downloadId,
                                'error' => $errorMessage
                            ];
                        }
                    }
                }

                Log::info('Company processing completed', [
                    'sync_id' => $this->syncId,
                    'cui' => $cui,
                    'company_name' => $companyName,
                    'messages_processed' => count($messages)
                ]);
            }

            // Mark as completed with last error if any
            $this->updateSyncStatus('completed', count($this->companies), 0, 0, count($this->companies), null, null, null, $totalProcessed, $totalErrors, $this->lastError);

            Log::info('Sequential sync completed', [
                'sync_id' => $this->syncId,
                'total_processed' => $totalProcessed,
                'total_errors' => $totalErrors
            ]);

            // Set a short expiry for completed status (30 seconds) to stop frontend polling
            $completedStatus = cache()->get("efactura_sync_status");
            if ($completedStatus) {
                cache()->put("efactura_sync_status", $completedStatus, 30); // Expire after 30 seconds
            }

        } catch (\Exception $e) {
            Log::error('Sequential sync failed', [
                'sync_id' => $this->syncId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->updateSyncStatus('failed', 0, 0, 0, count($this->companies), null, null, null, $totalProcessed, $totalErrors, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update sync status with detailed progress
     */
    private function updateSyncStatus(
        string $status,
        int $currentCompany = 0,
        int $currentInvoice = 0,
        int $totalInvoicesForCompany = 0,
        int $totalCompanies = 0,
        ?string $cui = null,
        ?string $companyName = null,
        ?string $invoiceIdentifier = null,
        int $totalProcessed = 0,
        int $totalErrors = 0,
        ?string $error = null
    ): void {
        $statusData = [
            'is_syncing' => in_array($status, ['processing']),
            'sync_id' => $this->syncId,
            'status' => $status,
            'current_company' => $currentCompany,
            'total_companies' => $totalCompanies,
            'current_invoice' => $currentInvoice,
            'total_invoices_for_company' => $totalInvoicesForCompany,
            'cui' => $cui,
            'company_name' => $companyName,
            'invoice_identifier' => $invoiceIdentifier,
            'total_processed' => $totalProcessed,
            'total_errors' => $totalErrors,
            'last_error' => $error,
            'error_details' => $status === 'completed' || $status === 'failed' ? $this->errorDetails : null,
            'last_update' => now()->toISOString(),
            'test_mode' => $this->testMode
        ];

        cache()->put("efactura_sync_status_{$this->syncId}", $statusData, 3600);

        // Also store generic sync status for frontend polling
        cache()->put("efactura_sync_status", $statusData, 3600);

        Log::info('Sync status updated', [
            'sync_id' => $this->syncId,
            'status' => $status,
            'progress' => $currentCompany > 0 ? "Company {$currentCompany}/{$totalCompanies}" : '',
            'invoice_progress' => $currentInvoice > 0 ? "Invoice {$currentInvoice}/{$totalInvoicesForCompany}" : ''
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Sequential sync job failed permanently', [
            'sync_id' => $this->syncId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->updateSyncStatus('failed', 0, 0, 0, count($this->companies), null, null, null, 0, 0, $exception->getMessage());
    }
}