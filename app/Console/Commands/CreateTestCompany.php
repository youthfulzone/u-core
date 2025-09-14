<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class CreateTestCompany extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-test-company';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a test company for e-Factura sync testing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Create test companies if they don't exist
        $companies = [
            ['cui' => '49423933', 'denumire' => 'STETCO MARIANA-FLORENTINA II'],
            ['cui' => '36221711', 'denumire' => 'YOUTHFUL ZONE SRL']
        ];

        foreach ($companies as $companyData) {
            $existing = Company::where('cui', $companyData['cui'])->first();
            if (!$existing) {
                $company = Company::create($companyData);
                $this->info("Test company created: {$company->cui} - {$company->denumire}");
            } else {
                $this->info("Company already exists: {$companyData['cui']} - {$companyData['denumire']}");
            }
        }

        $totalCompanies = Company::count();
        $companiesWithCui = Company::whereNotNull('cui')->where('cui', '!=', '')->count();

        $this->info("Total companies: {$totalCompanies}");
        $this->info("Companies with CUI: {$companiesWithCui}");

        // Test sync functionality
        if ($companiesWithCui > 0) {
            $this->info("Testing sync functionality...");

            try {
                $syncId = \Str::uuid()->toString();
                $startDate = \Carbon\Carbon::now()->subDays(30);
                $endDate = \Carbon\Carbon::now();

                // Get companies for sync
                $cuisToSync = Company::whereNotNull('cui')
                    ->where('cui', '!=', '')
                    ->get(['cui', 'denumire'])
                    ->map(function($company) {
                        return [
                            'cui' => $company->cui,
                            'name' => $company->denumire
                        ];
                    })
                    ->toArray();

                if (!empty($cuisToSync)) {
                    // Dispatch the job
                    \App\Jobs\ProcessEfacturaSequential::dispatch(
                        $syncId,
                        $cuisToSync,
                        $startDate,
                        $endDate,
                        null, // filter
                        true  // test mode
                    )->onQueue('efactura-sync');

                    $this->info("Sequential sync job dispatched with ID: {$syncId}");
                    $this->info("Companies to sync: " . count($cuisToSync));

                    // Check jobs table
                    $jobsCount = \DB::table('jobs')->count();
                    $this->info("Jobs in queue: {$jobsCount}");
                } else {
                    $this->error("No companies found for sync");
                }
            } catch (\Exception $e) {
                $this->error("Error testing sync: " . $e->getMessage());
            }
        } else {
            $this->error("No companies with CUI found - cannot test sync");
        }
    }
}
