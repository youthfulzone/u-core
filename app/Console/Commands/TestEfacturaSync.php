<?php

namespace App\Console\Commands;

use App\Services\AnafEfacturaService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TestEfacturaSync extends Command
{
    protected $signature = 'efactura:test-sync {cui : The CUI to test}';
    protected $description = 'Test e-Factura sync for a specific CUI';

    public function handle(): int
    {
        $cui = $this->argument('cui');
        $service = app(AnafEfacturaService::class);
        
        $this->info("Testing e-Factura sync for CUI: $cui");
        
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays(30);
        
        $this->info("Date range: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");
        
        try {
            // Test single page first
            $this->info("\n1. Testing single page API call...");
            $result = $service->listMessagesPaginated($cui, $startDate, $endDate, 1, null);
            
            $this->info("API Response:");
            $this->info("- Current page: " . ($result['currentPage'] ?? 'N/A'));
            $this->info("- Messages count: " . count($result['messages']));
            
            if (isset($result['error'])) {
                $this->error("API Error: " . $result['error']);
                return self::FAILURE;
            }
            
            if (!empty($result['messages'])) {
                $this->info("\nFirst message structure:");
                $firstMessage = $result['messages'][0];
                foreach ($firstMessage as $key => $value) {
                    $this->info("  - $key: " . (is_array($value) ? json_encode($value) : $value));
                }
            } else {
                $this->warn("No messages found for this CUI in the specified date range.");
            }
            
            // Test getting all messages
            $this->info("\n2. Testing getAllMessagesPaginated...");
            $allMessages = $service->getAllMessagesPaginated($cui, $startDate, $endDate, null);
            $this->info("Total messages retrieved: " . count($allMessages));
            
            if (!empty($allMessages)) {
                $this->info("\nMessage IDs found:");
                foreach ($allMessages as $index => $message) {
                    if ($index >= 5) {
                        $this->info("  ... and " . (count($allMessages) - 5) . " more");
                        break;
                    }
                    $id = $message['id_descarcare'] ?? 'NO ID';
                    $type = $message['tip'] ?? 'NO TYPE';
                    $this->info("  - ID: $id, Type: $type");
                }
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("Stack trace:");
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }
        
        return self::SUCCESS;
    }
}