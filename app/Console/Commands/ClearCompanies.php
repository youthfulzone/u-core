<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class ClearCompanies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'companies:clear {--force : Force the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all companies from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('This will delete ALL companies from the database. Are you sure?')) {
            $this->info('Operation cancelled.');
            return self::FAILURE;
        }

        try {
            $count = Company::count();
            Company::truncate();
            
            $this->info("Successfully deleted {$count} companies from the database.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to clear companies: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
