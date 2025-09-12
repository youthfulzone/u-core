<?php

namespace App\Console\Commands;

use App\Models\AnafCredential;
use Illuminate\Console\Command;

class UpdateAnafCui extends Command
{
    protected $signature = 'anaf:update-cui 
                            {cui : The real Romanian CUI (tax ID) - numeric value}
                            {--force : Force update even if CUI exists}';

    protected $description = 'Update the ANAF credentials with the real Romanian CUI (tax ID)';

    public function handle(): int
    {
        $cui = $this->argument('cui');
        
        // Validate CUI format (Romanian CUI is numeric, typically 8-10 digits)
        if (!preg_match('/^[0-9]{6,10}$/', $cui)) {
            $this->error('Invalid CUI format. Romanian CUI should be a numeric value between 6-10 digits.');
            $this->info('Example: php artisan anaf:update-cui 12345678');
            return self::FAILURE;
        }

        // Get the active credential
        $credential = AnafCredential::active()->first();
        
        if (!$credential) {
            $this->error('No active ANAF credentials found.');
            $this->info('Please run: php artisan efactura:import-credentials first');
            return self::FAILURE;
        }

        // Show current vs new CUI
        $this->info('Current client_id (encrypted): ' . substr($credential->client_id, 0, 20) . '...');
        $this->info('New CUI (real tax ID): ' . $cui);
        
        if (!$this->option('force')) {
            if (!$this->confirm('Do you want to update the CUI to the real Romanian tax ID?')) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        // Update the credential with real CUI
        $credential->client_id = $cui;
        $credential->save();
        
        $this->info('✅ ANAF credentials updated successfully!');
        $this->table(
            ['Field', 'Value'],
            [
                ['Environment', $credential->environment],
                ['Client ID (CUI)', $cui],
                ['Redirect URI', $credential->redirect_uri],
                ['Status', 'Active'],
                ['Updated At', now()->format('Y-m-d H:i:s')]
            ]
        );
        
        $this->newLine();
        $this->warn('⚠️  Important: You may need to re-authenticate with ANAF after changing the CUI.');
        $this->info('Visit /efactura in your browser to re-authenticate if needed.');
        
        return self::SUCCESS;
    }
}