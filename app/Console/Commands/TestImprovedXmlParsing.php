<?php

namespace App\Console\Commands;

use App\Models\EfacturaInvoice;
use App\Services\AnafEfacturaService;
use Illuminate\Console\Command;

class TestImprovedXmlParsing extends Command
{
    protected $signature = 'invoice:test-parsing {--limit=3 : Number of invoices to test}';
    protected $description = 'Test improved XML parsing with real invoice data';

    public function handle(): int
    {
        $limit = $this->option('limit');
        
        $invoices = EfacturaInvoice::whereNotNull('xml_content')
            ->limit($limit)
            ->get();
        
        if ($invoices->isEmpty()) {
            $this->warn('No invoices with XML content found');
            return self::SUCCESS;
        }
        
        $anafService = new AnafEfacturaService();
        $this->info("Testing improved XML parsing on {$invoices->count()} invoice(s)...\n");
        
        foreach ($invoices as $index => $invoice) {
            $this->info("=== Invoice " . ($index + 1) . " ===");
            $this->info("ID: {$invoice->_id}");
            $this->info("Stored Number: {$invoice->invoice_number}");
            $this->info("Stored Supplier: {$invoice->supplier_name}");
            $this->info("Stored Customer: {$invoice->customer_name}");
            
            if ($invoice->xml_content) {
                try {
                    // Use reflection to test the private parseInvoiceXML method
                    $reflection = new \ReflectionClass($anafService);
                    $method = $reflection->getMethod('parseInvoiceXML');
                    $method->setAccessible(true);
                    
                    $parsedData = $method->invoke($anafService, $invoice->xml_content);
                    
                    $this->info("✓ Parsed Successfully");
                    $this->info("New Number: {$parsedData['invoice_number']}");
                    $this->info("New Supplier: {$parsedData['supplier_name']}");
                    $this->info("New Customer: {$parsedData['customer_name']}");
                    $this->info("Status: {$parsedData['status']}");
                    
                    if ($parsedData['supplier_name'] !== $invoice->supplier_name) {
                        $this->line("<fg=yellow>  → Supplier name improved!</>");
                    }
                    
                    if ($parsedData['customer_name'] !== $invoice->customer_name) {
                        $this->line("<fg=yellow>  → Customer name improved!</>");
                    }
                    
                } catch (\Exception $e) {
                    $this->error("  Error parsing: " . $e->getMessage());
                }
            }
            
            $this->newLine();
        }
        
        return self::SUCCESS;
    }
}
