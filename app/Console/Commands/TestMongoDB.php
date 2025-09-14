<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestMongoDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-mongodb';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test MongoDB connection and sync job';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $this->info('Testing MongoDB connection...');
            $count = \App\Models\Company::count();
            $this->info("MongoDB works! Company count: {$count}");

            $this->info('Testing cache...');
            \Cache::put('test_key', 'test_value', 60);
            $cached = \Cache::get('test_key');
            $this->info("Cache works! Value: {$cached}");

            $this->info('Testing job dispatch...');
            $syncId = \Str::uuid()->toString();

            // Set initial sync status in cache
            \Cache::put("efactura_sync_status_{$syncId}", [
                'is_syncing' => true,
                'sync_id' => $syncId,
                'status' => 'testing',
                'current_company' => 1,
                'total_companies' => 2,
                'current_invoice' => 0,
                'total_invoices_for_company' => 0,
                'test_mode' => true,
                'last_update' => now()->toISOString()
            ], 3600);

            $this->info("Test sync status cached with ID: {$syncId}");

            // Test retrieving cache
            $status = \Cache::get("efactura_sync_status_{$syncId}");
            if ($status) {
                $this->info("Cache retrieval works! Status: " . $status['status']);
            } else {
                $this->error("Cache retrieval failed!");
            }

            // Check for generic sync status (what frontend uses)
            $genericStatus = \Cache::get("efactura_sync_status");
            if ($genericStatus) {
                $this->info("Generic sync status found! Status: " . $genericStatus['status']);
                $this->info("Company progress: " . $genericStatus['current_company'] . '/' . $genericStatus['total_companies']);
                $this->info("Total processed: " . ($genericStatus['total_processed'] ?? 0));
                $this->info("Total errors: " . ($genericStatus['total_errors'] ?? 0));
                $this->info("Is syncing: " . ($genericStatus['is_syncing'] ? 'true' : 'false'));
                $this->info("Last error: " . ($genericStatus['last_error'] ?? 'none'));

                // Show error details if available
                if (isset($genericStatus['error_details'])) {
                    $errorDetails = $genericStatus['error_details'];
                    $this->info("API Errors: " . count($errorDetails['api_errors'] ?? []));
                    $this->info("DB Errors: " . count($errorDetails['db_errors'] ?? []));
                    $this->info("Other Errors: " . count($errorDetails['other_errors'] ?? []));

                    if (!empty($errorDetails['api_errors'])) {
                        $this->error("Sample API Error: " . $errorDetails['api_errors'][0]['error']);
                    }
                    if (!empty($errorDetails['db_errors'])) {
                        $this->error("Sample DB Error: " . $errorDetails['db_errors'][0]['error']);
                    }
                }
            } else {
                $this->error("No generic sync status found - this is why frontend shows idle");
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
        }
    }
}
