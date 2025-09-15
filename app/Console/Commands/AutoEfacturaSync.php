<?php

namespace App\Console\Commands;

use App\Jobs\SimpleEfacturaSync;
use App\Models\Company;
use App\Models\EfacturaInvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoEfacturaSync extends Command
{
    protected $signature = 'efactura:auto-sync {--days=60 : Number of days to sync} {--report-only : Only generate report without syncing}';
    protected $description = 'Automatically sync e-Factura invoices and generate comprehensive report';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $reportOnly = $this->option('report-only');

        $this->info("🚀 Starting automatic e-Factura sync at " . now()->format('Y-m-d H:i:s'));
        $this->info("📅 Syncing last {$days} days of invoices");

        if ($reportOnly) {
            $this->info("📊 Report-only mode - no syncing will be performed");
            return $this->generateReport($days);
        }

        // Generate pre-sync report
        $this->line("");
        $this->info("📊 PRE-SYNC REPORT");
        $this->generateReport($days, 'pre-sync');

        // Start sync process
        $syncId = 'auto-sync-' . now()->format('Y-m-d-H-i-s');

        try {
            $this->info("");
            $this->info("🔄 Dispatching sync job (ID: {$syncId})");

            SimpleEfacturaSync::dispatch($syncId, $days)->onQueue('efactura-sync');

            $this->info("✅ Sync job dispatched successfully");
            $this->info("⏳ Monitoring sync progress...");

            // Monitor sync progress
            $this->monitorSyncProgress($syncId);

            $this->line("");
            $this->info("📊 POST-SYNC REPORT");
            $this->generateReport($days, 'post-sync', $syncId);

        } catch (\Exception $e) {
            $this->error("❌ Failed to start sync: " . $e->getMessage());
            Log::error('Auto sync failed', [
                'sync_id' => $syncId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    private function monitorSyncProgress(string $syncId): void
    {
        $maxWaitTime = 3600; // 1 hour max
        $pollInterval = 10; // 10 seconds
        $startTime = time();

        while ((time() - $startTime) < $maxWaitTime) {
            $status = cache()->get("efactura_sync_status_{$syncId}");

            if (!$status) {
                $this->warn("⚠️ No status found for sync ID: {$syncId}");
                sleep($pollInterval);
                continue;
            }

            $message = $status['message'] ?? 'Processing...';
            $currentCompany = $status['current_company'] ?? 0;
            $totalCompanies = $status['total_companies'] ?? 0;

            if ($status['status'] === 'running') {
                $this->line("📊 {$message}");

                // Show rate limit warnings if any
                if (isset($status['last_error']) && str_contains($status['last_error'], 'rate limit')) {
                    $this->warn("⚠️ Rate limit encountered - waiting and retrying...");
                }

            } elseif ($status['status'] === 'completed') {
                $totalProcessed = $status['total_processed'] ?? 0;
                $totalErrors = $status['total_errors'] ?? 0;
                $this->info("✅ Sync completed successfully!");
                $this->info("📈 Total processed: {$totalProcessed} invoices");
                if ($totalErrors > 0) {
                    $this->warn("⚠️ Total errors: {$totalErrors}");
                }
                break;

            } elseif ($status['status'] === 'failed') {
                $this->error("❌ Sync failed: " . ($status['message'] ?? 'Unknown error'));
                break;
            }

            sleep($pollInterval);
        }

        if ((time() - $startTime) >= $maxWaitTime) {
            $this->warn("⏰ Sync monitoring timed out after 1 hour");
        }
    }

    private function generateReport(int $days, string $reportType = 'current', ?string $syncId = null): int
    {
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);

        $this->line("═══════════════════════════════════════════════════════════");
        $this->info("📊 E-FACTURA SYNC REPORT ({$reportType})");
        $this->line("═══════════════════════════════════════════════════════════");
        $this->info("📅 Period: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')} ({$days} days)");
        $this->info("🕐 Generated: " . now()->format('Y-m-d H:i:s'));
        if ($syncId) {
            $this->info("🆔 Sync ID: {$syncId}");
        }
        $this->line("");

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

        $this->info("🏢 Total companies with valid CUIs: " . count($validCompanies));
        $this->line("");

        $grandTotalInvoices = 0;
        $grandTotalSuccessful = 0;
        $grandTotalFailed = 0;
        $grandTotalSkipped = 0;

        // Generate report for each company
        foreach ($validCompanies as $index => $companyInfo) {
            $cui = $companyInfo['cui'];
            $companyName = $companyInfo['name'];

            // Get invoices for this company in the date range
            $query = EfacturaInvoice::where('cui', $cui)
                ->whereBetween('archived_at', [$startDate, $endDate]);

            if ($syncId) {
                // For post-sync report, only show invoices from this sync
                $query->where('sync_id', $syncId);
            }

            $invoices = $query->get();

            $totalInvoices = $invoices->count();
            $successfulInvoices = $invoices->where('download_status', 'downloaded')->count();
            $failedInvoices = $invoices->where('download_status', 'failed')->count();
            $skippedInvoices = $totalInvoices - $successfulInvoices - $failedInvoices;

            // Only show companies with invoices (unless it's a full report)
            if ($totalInvoices > 0 || $reportType === 'current') {
                $this->line("┌─ Company " . ($index + 1) . "/" . count($validCompanies) . " ─────────────────────────────────────────");
                $this->info("│ 🏢 {$companyName}");
                $this->info("│ 🆔 CUI: {$cui}");
                $this->info("│ 📊 Total invoices: {$totalInvoices}");

                if ($totalInvoices > 0) {
                    $this->info("│ ✅ Successful: {$successfulInvoices}");
                    $this->info("│ ❌ Failed: {$failedInvoices}");
                    if ($skippedInvoices > 0) {
                        $this->info("│ ⏭️ Skipped: {$skippedInvoices}");
                    }

                    // Show success rate
                    $successRate = $totalInvoices > 0 ? round(($successfulInvoices / $totalInvoices) * 100, 1) : 0;
                    $this->info("│ 📈 Success Rate: {$successRate}%");

                    // Show date range of invoices
                    $oldestInvoice = $invoices->min('invoice_date');
                    $newestInvoice = $invoices->max('invoice_date');
                    if ($oldestInvoice && $newestInvoice) {
                        $this->info("│ 📅 Invoice Date Range: {$oldestInvoice} to {$newestInvoice}");
                    }
                }
                $this->line("└─────────────────────────────────────────────────────────");
                $this->line("");
            }

            $grandTotalInvoices += $totalInvoices;
            $grandTotalSuccessful += $successfulInvoices;
            $grandTotalFailed += $failedInvoices;
            $grandTotalSkipped += $skippedInvoices;
        }

        // Summary
        $this->line("═══════════════════════════════════════════════════════════");
        $this->info("📊 SUMMARY");
        $this->line("═══════════════════════════════════════════════════════════");
        $this->info("🏢 Companies processed: " . count($validCompanies));
        $this->info("📄 Total invoices: {$grandTotalInvoices}");
        $this->info("✅ Successful downloads: {$grandTotalSuccessful}");
        $this->info("❌ Failed downloads: {$grandTotalFailed}");
        if ($grandTotalSkipped > 0) {
            $this->info("⏭️ Skipped (already existed): {$grandTotalSkipped}");
        }

        if ($grandTotalInvoices > 0) {
            $overallSuccessRate = round(($grandTotalSuccessful / $grandTotalInvoices) * 100, 1);
            $this->info("📈 Overall Success Rate: {$overallSuccessRate}%");
        }

        // Show rate limit statistics
        $this->line("");
        $this->info("📊 ANAF API STATISTICS");
        $rateLimiter = new \App\Services\AnafRateLimiter();
        $stats = $rateLimiter->getStats();
        $this->info("🔄 API calls this minute: " . $stats['global_calls_this_minute']);
        $this->info("⏳ Remaining calls this minute: " . $stats['remaining_global_calls']);
        $this->info("🚦 Global limit per minute: " . $stats['global_limit_per_minute']);

        $this->line("═══════════════════════════════════════════════════════════");

        // Log the report
        Log::info('E-Factura sync report generated', [
            'report_type' => $reportType,
            'sync_id' => $syncId,
            'period_days' => $days,
            'companies_processed' => count($validCompanies),
            'total_invoices' => $grandTotalInvoices,
            'successful' => $grandTotalSuccessful,
            'failed' => $grandTotalFailed,
            'skipped' => $grandTotalSkipped,
            'success_rate' => $grandTotalInvoices > 0 ? round(($grandTotalSuccessful / $grandTotalInvoices) * 100, 1) : 0
        ]);

        return 0;
    }
}