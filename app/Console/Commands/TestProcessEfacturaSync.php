<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestProcessEfacturaSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-process-efactura-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually test ProcessEfacturaSequential job';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $this->info('Testing ProcessEfacturaSequential job manually...');

            $syncId = \Str::uuid()->toString();
            $startDate = \Carbon\Carbon::now()->subDays(30);
            $endDate = \Carbon\Carbon::now();

            // Get companies for sync
            $companies = \App\Models\Company::whereNotNull('cui')
                ->where('cui', '!=', '')
                ->get(['cui', 'denumire'])
                ->map(function($company) {
                    return [
                        'cui' => $company->cui,
                        'name' => $company->denumire
                    ];
                })
                ->toArray();

            if (empty($companies)) {
                $this->error('No companies found!');
                return;
            }

            $this->info("Found " . count($companies) . " companies for sync");

            // Create job instance manually
            $job = new \App\Jobs\ProcessEfacturaSequential(
                $syncId,
                $companies,
                $startDate,
                $endDate,
                null, // filter
                true  // test mode
            );

            $this->info("Job created with sync ID: {$syncId}");

            // Try to run the handle method directly
            $this->info('Attempting to run job handle method...');
            $job->handle();

            $this->info('Job completed successfully!');

        } catch (\Exception $e) {
            $this->error("Error testing job: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . " Line: " . $e->getLine());
            $this->error("Trace: " . $e->getTraceAsString());
        }
    }
}
