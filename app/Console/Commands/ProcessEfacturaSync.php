<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEfacturaSequential;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessEfacturaSync extends Command
{
    protected $signature = 'efactura:process-sync {sync_id} {companies} {start_date} {end_date} {filter?} {test_mode=false}';
    protected $description = 'Process e-factura sync in background';

    public function handle()
    {
        $syncId = $this->argument('sync_id');
        $companies = json_decode($this->argument('companies'), true);
        $startDate = Carbon::parse($this->argument('start_date'));
        $endDate = Carbon::parse($this->argument('end_date'));
        $filter = $this->argument('filter');
        $testMode = $this->argument('test_mode') === 'true';

        Log::info('Starting sync command', [
            'sync_id' => $syncId,
            'companies_count' => count($companies),
            'test_mode' => $testMode
        ]);

        try {
            $job = new ProcessEfacturaSequential(
                $syncId,
                $companies,
                $startDate,
                $endDate,
                $filter,
                $testMode
            );

            $job->handle();

            $this->info("Sync completed successfully for {$syncId}");
            Log::info('Sync command completed successfully', ['sync_id' => $syncId]);

        } catch (\Exception $e) {
            $this->error("Sync failed: " . $e->getMessage());
            Log::error('Sync command failed', [
                'sync_id' => $syncId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}