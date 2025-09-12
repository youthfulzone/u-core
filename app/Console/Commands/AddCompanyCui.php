<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class AddCompanyCui extends Command
{
    protected $signature = 'company:add-cui 
                            {cui : The Romanian CUI (tax ID) - numeric value}
                            {name : The company name}
                            {--update : Update if company already exists}';

    protected $description = 'Add a company with its CUI for e-Factura synchronization';

    public function handle(): int
    {
        $cui = $this->argument('cui');
        $name = $this->argument('name');
        
        // Validate CUI format (Romanian CUI is numeric, typically 6-10 digits)
        if (!preg_match('/^[0-9]{6,10}$/', $cui)) {
            $this->error('Invalid CUI format. Romanian CUI should be a numeric value between 6-10 digits.');
            $this->info('Example: php artisan company:add-cui 12345678 "Company Name SRL"');
            return self::FAILURE;
        }

        // Check if company already exists
        $existing = Company::where('cui', $cui)->first();
        
        if ($existing && !$this->option('update')) {
            $this->warn("Company with CUI {$cui} already exists: {$existing->denumire}");
            $this->info('Use --update flag to update the company name.');
            return self::SUCCESS;
        }

        if ($existing) {
            // Update existing company
            $existing->denumire = $name;
            $existing->save();
            
            $this->info('✅ Company updated successfully!');
        } else {
            // Create new company
            Company::create([
                'cui' => $cui,
                'denumire' => $name,
                'status' => 'active',
                'statusRO_e_Factura' => true,
                'manual_added' => true,
                'added_by' => 'system',
                'synced_at' => now()
            ]);
            
            $this->info('✅ Company added successfully!');
        }
        
        $this->table(
            ['Field', 'Value'],
            [
                ['CUI', $cui],
                ['Company Name', $name],
                ['Status', 'Active'],
                ['Action', $existing ? 'Updated' : 'Created']
            ]
        );
        
        $this->newLine();
        $this->info('You can now sync e-Facturi for this company by clicking "Sincronizare facturi" in the web interface.');
        
        return self::SUCCESS;
    }
}