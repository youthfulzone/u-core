<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ProcessAnafJsonCommand extends Command
{
    protected $signature = 'anaf:process-json {file? : Path to JSON file}';
    protected $description = 'Process manually downloaded ANAF JSON data';

    public function handle()
    {
        $this->info('ANAF JSON Processor');
        $this->info('===================');
        
        // Get file path
        $filePath = $this->argument('file') ?? storage_path('app/anaf_response.json');
        
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            $this->info("\nInstructions:");
            $this->info("1. Open https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60");
            $this->info("2. Authenticate with your certificate");
            $this->info("3. Copy the entire JSON response (Ctrl+A, Ctrl+C)");
            $this->info("4. Save to: {$filePath}");
            $this->info("5. Run this command again");
            return 1;
        }
        
        // Read and parse JSON
        $json = file_get_contents($filePath);
        $data = json_decode($json, true);
        
        if (!$data || !isset($data['mesaje'])) {
            $this->error('Invalid JSON structure');
            return 1;
        }
        
        // Display summary
        $this->info("\n✅ ANAF Data Loaded Successfully!");
        $this->table(
            ['Field', 'Value'],
            [
                ['Total Messages', count($data['mesaje'])],
                ['CNP', $data['cnp'] ?? 'N/A'],
                ['CUI', $data['cui'] ?? 'N/A'],
                ['Serial', $data['serial'] ?? 'N/A'],
            ]
        );
        
        // Process messages
        $this->info("\nProcessing messages...");
        $bar = $this->output->createProgressBar(count($data['mesaje']));
        
        foreach ($data['mesaje'] as $message) {
            // Here you would save to database
            // For now, we'll just process the data
            $this->processMessage($message);
            $bar->advance();
        }
        
        $bar->finish();
        $this->info("\n\n✅ All messages processed!");
        
        // Save backup with timestamp
        $backupPath = storage_path('app/anaf_backups/' . date('Y-m-d_H-i-s') . '.json');
        if (!is_dir(dirname($backupPath))) {
            mkdir(dirname($backupPath), 0755, true);
        }
        file_put_contents($backupPath, json_encode($data, JSON_PRETTY_PRINT));
        $this->info("Backup saved to: {$backupPath}");
        
        // Display message types summary
        $this->displaySummary($data['mesaje']);
        
        return 0;
    }
    
    private function processMessage(array $message)
    {
        // Process each message
        // This is where you'd save to database
        
        // For now, just extract key information
        $processed = [
            'id' => $message['id'] ?? null,
            'type' => $message['tip'] ?? null,
            'cif' => $message['cif'] ?? null,
            'details' => $message['detalii'] ?? null,
            'created_at' => $message['data_creare'] ?? null,
            'request_id' => $message['id_solicitare'] ?? null,
        ];
        
        // Log or save to database
        // DB::table('anaf_messages')->updateOrInsert(['id' => $processed['id']], $processed);
    }
    
    private function displaySummary(array $messages)
    {
        $this->info("\n📊 Message Types Summary:");
        
        $types = [];
        foreach ($messages as $msg) {
            $type = $msg['tip'] ?? 'UNKNOWN';
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        
        $rows = [];
        foreach ($types as $type => $count) {
            $rows[] = [$type, $count];
        }
        
        $this->table(['Type', 'Count'], $rows);
        
        // Display CIF summary
        $cifs = [];
        foreach ($messages as $msg) {
            if (isset($msg['cif'])) {
                $cifs[$msg['cif']] = ($cifs[$msg['cif']] ?? 0) + 1;
            }
        }
        
        if (!empty($cifs)) {
            $this->info("\n🏢 Messages by CIF:");
            $cifRows = [];
            foreach ($cifs as $cif => $count) {
                $cifRows[] = [$cif, $count];
            }
            $this->table(['CIF', 'Message Count'], $cifRows);
        }
    }
}