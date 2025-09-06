<?php

namespace App\Jobs;

use App\Models\CompanyQueue;
use App\Services\AnafCompanyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessCompanyQueue implements ShouldQueue
{
    use Queueable;

    public string $cui;
    
    public int $tries = 3;
    
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(string $cui)
    {
        $this->cui = $cui;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('🚀 JOB STARTED: Processing company queue item', ['cui' => $this->cui]);
        
        $companyService = app(AnafCompanyService::class);
        
        try {
            Log::info('📡 Calling processCompanyQueue', ['cui' => $this->cui]);
            $result = $companyService->processCompanyQueue($this->cui);
            
            Log::info('📊 processCompanyQueue result', [
                'cui' => $this->cui,
                'success' => $result['success'],
                'result' => $result
            ]);
            
            if ($result['success']) {
                Log::info('✅ Company data fetched successfully', [
                    'cui' => $this->cui,
                    'has_data' => !empty($result['data'])
                ]);
                
                // Auto-approve if data was found
                if (!empty($result['data']['found']) && count($result['data']['found']) > 0) {
                    Log::info('🔄 Auto-approving company', ['cui' => $this->cui]);
                    $approveResult = $companyService->approveCompany($this->cui);
                    Log::info('✅ Company auto-approved', [
                        'cui' => $this->cui, 
                        'approve_result' => $approveResult
                    ]);
                } else {
                    Log::warning('⚠️ No valid data found for approval', ['cui' => $this->cui]);
                }
            } else {
                Log::warning('❌ Failed to fetch company data', [
                    'cui' => $this->cui,
                    'message' => $result['message']
                ]);
            }
            
            Log::info('✅ JOB COMPLETED: Processing company queue item', ['cui' => $this->cui]);
            
        } catch (\Exception $e) {
            Log::error('💥 JOB FAILED: Error processing company queue', [
                'cui' => $this->cui,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw to trigger retry
            throw $e;
        }
    }
}