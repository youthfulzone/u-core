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

class ProcessEfacturaSync implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 3600; // 1 hour timeout for high volume
    public int $tries = 3;
    public int $backoff = 60; // 1 minute backoff between retries

    public function __construct(
        public string $syncId,
        public string $cui,
        public string $companyName,
        public Carbon $startDate,
        public Carbon $endDate,
        public ?string $filter = null,
        public int $batchSize = 50,
        public bool $testMode = false
    ) {
        // Set queue name based on volume
        $this->onQueue('efactura-sync');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $efacturaService = new AnafEfacturaService();

        Log::info('Starting queued e-factura sync', [
            'sync_id' => $this->syncId,
            'cui' => $this->cui,
            'company_name' => $this->companyName,
            'batch_size' => $this->batchSize
        ]);

        try {
            // Update sync status
            $this->updateSyncStatus('fetching_messages');

            // Get messages from ANAF
            $messages = $efacturaService->getAllMessagesPaginated(
                $this->cui,
                $this->startDate,
                $this->endDate,
                $this->filter
            );

            Log::info('Messages fetched for queued sync', [
                'sync_id' => $this->syncId,
                'cui' => $this->cui,
                'total_messages' => count($messages)
            ]);

            if (empty($messages)) {
                $this->updateSyncStatus('completed', 0, 0);
                return;
            }

            // Process messages in batches
            $chunks = array_chunk($messages, $this->batchSize);
            $totalProcessed = 0;
            $totalErrors = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                $this->updateSyncStatus('processing', $totalProcessed, count($messages));

                Log::info('Processing batch', [
                    'sync_id' => $this->syncId,
                    'cui' => $this->cui,
                    'batch' => $chunkIndex + 1,
                    'total_batches' => count($chunks),
                    'batch_size' => count($chunk)
                ]);

                foreach ($chunk as $message) {
                    try {
                        $downloadId = $message['id'] ?? $message['id_descarcare'] ?? null;

                        if (!$downloadId) {
                            $totalErrors++;
                            continue;
                        }

                        // Check if already exists
                        if (EfacturaInvoice::where('download_id', $downloadId)->exists()) {
                            $totalProcessed++;
                            continue;
                        }

                        // Download and store
                        $downloadData = $efacturaService->downloadMessage($downloadId, $message);
                        $invoiceData = $downloadData['invoice_data'];

                        EfacturaInvoice::create([
                            'cui' => $this->cui,
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
                            // PDF will be generated on-demand only - not during sync for better performance
                            'pdf_content' => null,
                            'pdf_generated_at' => null
                        ]);

                        $totalProcessed++;

                        // ANAF API Rate Limiting using the dedicated rate limiter
                        $rateLimiter = new AnafRateLimiter();
                        $rateLimiter->waitForNextCall($this->testMode);

                    } catch (\Exception $e) {
                        Log::error('Error processing invoice in queue', [
                            'sync_id' => $this->syncId,
                            'cui' => $this->cui,
                            'download_id' => $downloadId ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                        $totalErrors++;
                    }
                }

                // Update progress after each batch
                $this->updateSyncStatus('processing', $totalProcessed, count($messages));
            }

            // Mark as completed
            $this->updateSyncStatus('completed', $totalProcessed, count($messages), $totalErrors);

            Log::info('Queued sync completed', [
                'sync_id' => $this->syncId,
                'cui' => $this->cui,
                'total_processed' => $totalProcessed,
                'total_errors' => $totalErrors
            ]);

        } catch (\Exception $e) {
            Log::error('Queued sync failed', [
                'sync_id' => $this->syncId,
                'cui' => $this->cui,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->updateSyncStatus('failed', 0, 0, 1, $e->getMessage());
            throw $e;
        }
    }

    private function updateSyncStatus(string $status, int $processed = 0, int $total = 0, int $errors = 0, ?string $error = null): void
    {
        cache()->put("efactura_sync_status_{$this->syncId}", [
            'is_syncing' => in_array($status, ['fetching_messages', 'processing']),
            'sync_id' => $this->syncId,
            'cui' => $this->cui,
            'company_name' => $this->companyName,
            'status' => $status,
            'processed_invoices' => $processed,
            'total_invoices' => $total,
            'total_errors' => $errors,
            'last_error' => $error,
            'last_update' => now()->toISOString()
        ], 3600); // Cache for 1 hour
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Queued sync job failed permanently', [
            'sync_id' => $this->syncId,
            'cui' => $this->cui,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->updateSyncStatus('failed', 0, 0, 1, $exception->getMessage());
    }
}
