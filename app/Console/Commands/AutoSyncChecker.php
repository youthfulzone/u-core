<?php

namespace App\Console\Commands;

use App\Models\EfacturaAutoSync;
use App\Jobs\SimpleEfacturaSync;
use App\Models\Company;
use App\Models\EfacturaInvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoSyncChecker extends Command
{
    protected $signature = 'efactura:check-auto-sync';
    protected $description = 'Check if auto-sync should run and execute if needed';

    public function handle(): int
    {
        $config = EfacturaAutoSync::getConfig();

        if (!$config->shouldRun()) {
            $this->info('Auto-sync not scheduled to run yet.');
            return 0;
        }

        $this->info('🚀 Starting automatic e-Factura sync...');
        Log::info('Auto-sync triggered via web scheduler');

        try {
            // Mark as running
            $config->markAsRunning();

            // Generate sync ID
            $syncId = 'web-auto-sync-' . now()->format('Y-m-d-H-i-s');

            // Dispatch the sync job
            SimpleEfacturaSync::dispatch($syncId, $config->sync_days)->onQueue('efactura-sync');

            $this->info("✅ Auto-sync job dispatched (ID: {$syncId})");

            // Monitor the sync and generate report when complete
            $this->monitorSyncAndGenerateReport($config, $syncId);

        } catch (\Exception $e) {
            $config->markAsFailed($e->getMessage());
            $this->error('❌ Auto-sync failed: ' . $e->getMessage());
            Log::error('Auto-sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    private function monitorSyncAndGenerateReport(EfacturaAutoSync $config, string $syncId): void
    {
        $maxWaitTime = 7200; // 2 hours max
        $pollInterval = 30; // 30 seconds
        $startTime = time();

        while ((time() - $startTime) < $maxWaitTime) {
            $status = cache()->get("efactura_sync_status_{$syncId}");

            if (!$status) {
                sleep($pollInterval);
                continue;
            }

            if ($status['status'] === 'completed') {
                $this->info('✅ Auto-sync completed successfully!');

                // Generate comprehensive report
                $report = $this->generateSyncReport($config->sync_days, $syncId);

                // Mark as completed with report
                $config->markAsCompleted($report);

                Log::info('Auto-sync completed successfully', [
                    'sync_id' => $syncId,
                    'report' => $report
                ]);

                return;

            } elseif ($status['status'] === 'failed') {
                $error = $status['message'] ?? 'Unknown error';
                $config->markAsFailed($error);
                $this->error('❌ Auto-sync failed: ' . $error);
                return;
            }

            // Still running, wait and check again
            sleep($pollInterval);
        }

        // Timeout reached
        $config->markAsFailed('Auto-sync timed out after 2 hours');
        $this->warn('⏰ Auto-sync monitoring timed out');
    }

    private function generateSyncReport(int $days, string $syncId): array
    {
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);

        // Get all companies
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

        $companyReports = [];
        $grandTotalInvoices = 0;
        $grandTotalSuccessful = 0;
        $grandTotalFailed = 0;

        // Generate report for each company
        foreach ($validCompanies as $companyInfo) {
            $cui = $companyInfo['cui'];
            $companyName = $companyInfo['name'];

            // Get invoices for this sync
            $invoices = EfacturaInvoice::where('cui', $cui)
                ->where('sync_id', $syncId)
                ->get();

            $totalInvoices = $invoices->count();
            $successfulInvoices = $invoices->where('download_status', 'downloaded')->count();
            $failedInvoices = $invoices->where('download_status', 'failed')->count();

            if ($totalInvoices > 0) {
                $companyReports[] = [
                    'company_name' => $companyName,
                    'cui' => $cui,
                    'total_invoices' => $totalInvoices,
                    'successful' => $successfulInvoices,
                    'failed' => $failedInvoices,
                    'success_rate' => round(($successfulInvoices / $totalInvoices) * 100, 1),
                    'oldest_invoice' => $invoices->min('invoice_date'),
                    'newest_invoice' => $invoices->max('invoice_date')
                ];
            }

            $grandTotalInvoices += $totalInvoices;
            $grandTotalSuccessful += $successfulInvoices;
            $grandTotalFailed += $failedInvoices;
        }

        return [
            'sync_id' => $syncId,
            'period_days' => $days,
            'period_start' => $startDate->format('Y-m-d'),
            'period_end' => $endDate->format('Y-m-d'),
            'generated_at' => now()->toISOString(),
            'companies_processed' => count($validCompanies),
            'companies_with_invoices' => count($companyReports),
            'company_reports' => $companyReports,
            'summary' => [
                'total_invoices' => $grandTotalInvoices,
                'successful' => $grandTotalSuccessful,
                'failed' => $grandTotalFailed,
                'success_rate' => $grandTotalInvoices > 0 ? round(($grandTotalSuccessful / $grandTotalInvoices) * 100, 1) : 0
            ]
        ];
    }
}