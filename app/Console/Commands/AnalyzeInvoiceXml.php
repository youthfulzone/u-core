<?php

namespace App\Console\Commands;

use App\Models\EfacturaInvoice;
use Illuminate\Console\Command;

class AnalyzeInvoiceXml extends Command
{
    protected $signature = 'invoice:analyze-xml {--limit=3 : Number of invoices to analyze}';
    protected $description = 'Analyze XML structure of invoices to improve parsing';

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
        
        $this->info("Analyzing {$invoices->count()} invoice(s)...\n");
        
        foreach ($invoices->take(3) as $index => $invoice) {
            $this->info("=== Invoice " . ($index + 1) . " ===");
            $this->info("ID: {$invoice->_id}");
            $this->info("Number: {$invoice->invoice_number}");
            $this->info("Current Supplier: {$invoice->supplier_name}");
            $this->info("Current Customer: {$invoice->customer_name}");
            
            if ($invoice->xml_content) {
                try {
                    $xmlData = $invoice->xml_content;
                    $this->info("XML Content Length: " . strlen($xmlData));
                    
                    // Try to decode if it's base64 encoded
                    if (!str_starts_with($xmlData, '<')) {
                        $this->info("XML doesn't start with <, trying base64 decode...");
                        $decodedData = base64_decode($xmlData, true);
                        if ($decodedData !== false && str_starts_with($decodedData, '<')) {
                            $xmlData = $decodedData;
                            $this->info("Successfully decoded base64 XML");
                        }
                    }
                    
                    // Simple test first
                    if (strpos($xmlData, '<Invoice') !== false) {
                        $this->info("✓ Contains <Invoice tag");
                    }
                    if (strpos($xmlData, 'xmlns=') !== false) {
                        $this->info("✓ Contains xmlns namespace declarations");
                    }
                    
                    // Save XML to temp file for inspection
                    $tempFile = storage_path('app/temp_invoice.xml');
                    file_put_contents($tempFile, $xmlData);
                    $this->info("XML saved to: {$tempFile}");
                    
                    // Try simplexml_load_string directly
                    libxml_use_internal_errors(true);
                    $xml = simplexml_load_string($xmlData, 'SimpleXMLElement', LIBXML_NOCDATA);
                    
                    if ($xml === false) {
                        $this->error("simplexml_load_string failed. Errors:");
                        $errors = libxml_get_errors();
                        foreach ($errors as $error) {
                            $this->error("  Line {$error->line}: {$error->message}");
                        }
                        libxml_clear_errors();
                    } else {
                        $this->analyzeXmlStructure($xml);
                        $this->info("✓ Root element: " . $xml->getName());
                        
                        // Get all namespaces
                        $namespaces = $xml->getNamespaces(true);
                        if (!empty($namespaces)) {
                            $this->info("Namespaces found:");
                            foreach ($namespaces as $prefix => $uri) {
                                $this->info("  '{$prefix}': {$uri}");
                            }
                        }
                        
                        // Look for supplier/customer patterns
                        $this->findSupplierCustomerPatterns($xml);
                    }
                } catch (\Exception $e) {
                    $this->error("Error analyzing invoice {$invoice->_id}: " . $e->getMessage());
                }
            }
            
            $this->newLine();
        }
        
        return self::SUCCESS;
    }
    
    private function analyzeXmlStructure(\SimpleXMLElement $xml): void
    {
        // Detect format type
        if (isset($xml->children('ubl', true)->Invoice) || $xml->getName() === 'Invoice') {
            $this->info("Format: UBL (Universal Business Language)");
        } elseif (isset($xml->children('rsm', true)->CrossIndustryInvoice)) {
            $this->info("Format: CII (Cross Industry Invoice)");
        } else {
            $this->info("Format: Generic/Unknown");
        }
    }
    
    private function findSupplierCustomerPatterns(\SimpleXMLElement $xml): void
    {
        // Common XPath patterns to search for
        $patterns = [
            // UBL patterns
            '//cac:AccountingSupplierParty//cbc:Name',
            '//cac:AccountingSupplierParty//cac:Party//cac:PartyName//cbc:Name',
            '//cac:AccountingSupplierParty//cac:Party//cac:PartyLegalEntity//cbc:RegistrationName',
            '//cac:AccountingCustomerParty//cbc:Name',
            '//cac:AccountingCustomerParty//cac:Party//cac:PartyName//cbc:Name',
            '//cac:AccountingCustomerParty//cac:Party//cac:PartyLegalEntity//cbc:RegistrationName',
            
            // CII patterns
            '//ram:SellerTradeParty//ram:Name',
            '//ram:BuyerTradeParty//ram:Name',
            
            // Generic patterns
            '//Supplier//Name',
            '//Customer//Name',
            '//Seller//Name',
            '//Buyer//Name',
        ];
        
        $this->info("Searching for supplier/customer information:");
        
        foreach ($patterns as $pattern) {
            $results = $xml->xpath($pattern);
            if (!empty($results)) {
                $this->info("  Found with pattern '{$pattern}':");
                foreach ($results as $result) {
                    $this->info("    - " . trim((string)$result));
                }
            }
        }
        
        // Also try to find any element containing common supplier/customer terms
        $this->info("Elements containing supplier/customer terms:");
        $this->searchForTerms($xml, ['supplier', 'customer', 'seller', 'buyer', 'party', 'furnizor', 'client']);
    }
    
    private function searchForTerms(\SimpleXMLElement $xml, array $terms): void
    {
        $xmlString = $xml->asXML();
        
        foreach ($terms as $term) {
            if (stripos($xmlString, $term) !== false) {
                // Try to find elements containing this term
                $xpath = "//*[contains(local-name(), '{$term}')]";
                $results = $xml->xpath($xpath);
                
                if (!empty($results)) {
                    $this->info("  Elements with '{$term}' in name: " . count($results));
                    foreach (array_slice($results, 0, 3) as $result) {
                        $this->info("    - " . $result->getName() . ": " . substr(trim((string)$result), 0, 50));
                    }
                }
            }
        }
    }
}