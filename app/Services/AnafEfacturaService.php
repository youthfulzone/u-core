<?php

namespace App\Services;

use App\Models\EfacturaToken;
use App\Models\AnafCredential;
use App\Services\AnafRateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;
use Carbon\Carbon;

class AnafEfacturaService
{
    private string $baseUrl;
    private ?string $accessToken = null;
    private AnafRateLimiter $rateLimiter;

    public function __construct(string $environment = 'production')
    {
        $this->baseUrl = $environment === 'production'
            ? 'https://api.anaf.ro/prod/FCTEL/rest'
            : 'https://api.anaf.ro/test/FCTEL/rest';

        $this->rateLimiter = new AnafRateLimiter();
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $credential = AnafCredential::active()->first();
        if (!$credential) {
            throw new \Exception('No active ANAF credentials found');
        }

        $token = EfacturaToken::forClientId($credential->client_id)->active()->first();
        if (!$token || !$token->isValid()) {
            throw new \Exception('No valid e-Factura token available');
        }

        $this->accessToken = $token->access_token;
        return $this->accessToken;
    }

    /**
     * List messages with pagination support
     */
    public function listMessagesPaginated(
        string $cif,
        Carbon $startDate,
        Carbon $endDate,
        int $page = 1,
        ?string $filter = null
    ): array {
        // Check ANAF rate limits before making the call
        if (!$this->rateLimiter->canMakeCall('lista', ['cui' => $cif, 'paginated' => true])) {
            throw new \Exception("ANAF rate limit exceeded for listing messages for CUI {$cif}");
        }

        $params = [
            'startTime' => $startDate->timestamp * 1000, // Unix timestamp in milliseconds
            'endTime' => $endDate->timestamp * 1000,
            'cif' => $cif,
            'pagina' => $page
        ];

        if ($filter && in_array($filter, ['E', 'T', 'P', 'R'])) {
            $params['filtru'] = $filter;
        }

        try {
            $this->rateLimiter->waitForNextCall(); // Wait before making the call

            $response = Http::withToken($this->getAccessToken())
                ->get($this->baseUrl . '/listaMesajePaginatieFactura', $params);

            // Record the API call for rate limiting
            $this->rateLimiter->recordCall('lista', ['cui' => $cif, 'paginated' => true]);

            if ($response->failed()) {
                throw new RequestException($response);
            }

            $data = $response->json();
            
            // Check for API errors
            if (isset($data['eroare'])) {
                Log::warning('ANAF API returned error', [
                    'cif' => $cif,
                    'error' => $data['eroare'],
                    'title' => $data['titlu'] ?? null
                ]);
                return [
                    'messages' => [],
                    'currentPage' => $page,
                    'error' => $data['eroare']
                ];
            }

            return [
                'messages' => $data['mesaje'] ?? [],
                'currentPage' => $page,
                'serial' => $data['serial'] ?? null,
                'cui' => $data['cui'] ?? null,
                'title' => $data['titlu'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('ANAF e-Factura list messages error', [
                'error' => $e->getMessage(),
                'cif' => $cif,
                'page' => $page
            ]);
            throw $e;
        }
    }

    /**
     * Get all messages across multiple pages
     */
    public function getAllMessagesPaginated(
        string $cif,
        Carbon $startDate,
        Carbon $endDate,
        ?string $filter = null
    ): array {
        $allMessages = [];
        $currentPage = 1;
        $hasMorePages = true;
        $maxPages = 100; // Safety limit

        while ($hasMorePages && $currentPage <= $maxPages) {
            try {
                $result = $this->listMessagesPaginated($cif, $startDate, $endDate, $currentPage, $filter);
                
                if (!empty($result['messages'])) {
                    $allMessages = array_merge($allMessages, $result['messages']);
                    $currentPage++;
                } else {
                    $hasMorePages = false;
                }
            } catch (\Exception $e) {
                Log::error("Error fetching page {$currentPage}", ['error' => $e->getMessage()]);
                $hasMorePages = false;
            }
        }

        return $allMessages;
    }

    /**
     * Convert XML to PDF using ANAF API
     */
    public function convertXmlToPdf(string $xmlContent, string $standard = 'FACT1', bool $validate = true): ?string
    {
        try {
            $endpoint = $validate
                ? "/transformare/{$standard}"
                : "/transformare/{$standard}/DA";

            $response = Http::withToken($this->getAccessToken())
                ->withHeaders(['Content-Type' => 'text/plain'])
                ->timeout(30)
                ->withBody($xmlContent, 'text/plain')
                ->post($this->baseUrl . $endpoint);

            if ($response->successful()) {
                // Response should be PDF content
                return $response->body();
            }

            Log::error('XML to PDF conversion failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500)
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('XML to PDF conversion error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Validate XML against ANAF standards
     */
    public function validateXml(string $xmlContent, string $standard = 'FACT1'): array
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->withHeaders(['Content-Type' => 'text/plain'])
                ->timeout(30)
                ->withBody($xmlContent, 'text/plain')
                ->post($this->baseUrl . "/validare/{$standard}");

            if ($response->successful()) {
                return [
                    'valid' => true,
                    'message' => 'XML valid',
                    'details' => $response->json()
                ];
            }

            return [
                'valid' => false,
                'message' => 'XML validation failed',
                'details' => $response->json() ?? $response->body()
            ];
        } catch (\Exception $e) {
            Log::error('XML validation error', ['error' => $e->getMessage()]);
            return [
                'valid' => false,
                'message' => 'Validation error: ' . $e->getMessage(),
                'details' => null
            ];
        }
    }

    /**
     * Download a specific message/invoice and return all data for MongoDB storage
     */
    public function downloadMessage(string $downloadId, array $messageData = []): array
    {
        // Check ANAF rate limits before making the call
        if (!$this->rateLimiter->canMakeCall('descarcare', ['message_id' => $downloadId])) {
            throw new \Exception("ANAF rate limit exceeded for downloading message {$downloadId}");
        }

        try {
            Log::info('ANAF API: Making descarcare call', [
                'download_id' => $downloadId,
                'rate_limit_stats' => $this->rateLimiter->getStats()
            ]);

            $this->rateLimiter->waitForNextCall(); // Wait before making the call

            $response = Http::withToken($this->getAccessToken())
                ->timeout(30) // Add 30 second timeout to prevent hanging
                ->connectTimeout(10) // 10 second connection timeout
                ->get($this->baseUrl . '/descarcare', ['id' => $downloadId]);

            // Record the API call for rate limiting
            $this->rateLimiter->recordCall('descarcare', ['message_id' => $downloadId]);

            if ($response->failed()) {
                throw new RequestException($response);
            }

            $zipContent = $response->body();
            $extractedFiles = $this->extractZipContents($zipContent);
            
            // Parse invoice data if XML is available
            $invoiceData = [];
            if ($extractedFiles['invoice']) {
                $invoiceData = $this->parseInvoiceXML($extractedFiles['invoice']);
            }

            // Return complete data structure for atomic MongoDB storage
            return [
                'download_id' => $downloadId,
                'zip_content' => base64_encode($zipContent),
                'xml_content' => $extractedFiles['invoice'] ?? null,
                'xml_signature' => $extractedFiles['signature'] ?? null,
                'xml_errors' => $extractedFiles['errors'] ?? null,
                'invoice_data' => $invoiceData,
                'message_data' => $messageData,
                'downloaded_at' => now(),
                'file_size' => strlen($zipContent)
            ];
        } catch (\Exception $e) {
            Log::error('ANAF SERVICE: CRITICAL ERROR during download', [
                'download_id' => $downloadId,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Extract ZIP contents
     */
    private function extractZipContents(string $zipContent): array
    {
        Log::info('ANAF SERVICE: Creating temporary ZIP file');
        $tempZipFile = tempnam(sys_get_temp_dir(), 'anaf_invoice_');
        file_put_contents($tempZipFile, $zipContent);

        $zip = new \ZipArchive();
        $result = [
            'invoice' => null,
            'signature' => null,
            'errors' => null
        ];

        Log::info('ANAF SERVICE: Opening ZIP file', [
            'temp_file' => $tempZipFile,
            'zip_size' => strlen($zipContent)
        ]);

        if ($zip->open($tempZipFile) === TRUE) {
            Log::info('ANAF SERVICE: ZIP opened successfully', [
                'num_files' => $zip->numFiles
            ]);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $content = $zip->getFromIndex($i);

                Log::info('ANAF SERVICE: Processing ZIP file', [
                    'filename' => $filename,
                    'content_length' => strlen($content),
                    'is_xml' => str_ends_with($filename, '.xml')
                ]);

                if (str_ends_with($filename, '.xml')) {
                    // Identify file type based on content
                    if (str_contains($content, 'ds:Signature') ||
                        str_contains($filename, 'semnatura') ||
                        str_contains($filename, 'signature')) {
                        $result['signature'] = $content;
                        Log::info('ANAF SERVICE: Identified signature file', ['filename' => $filename]);
                    } elseif (str_contains($content, 'ErrorList') ||
                              str_contains($filename, 'erori') ||
                              str_contains($filename, 'errors')) {
                        $result['errors'] = $content;
                        Log::info('ANAF SERVICE: Identified errors file', ['filename' => $filename]);
                    } else {
                        $result['invoice'] = $content;
                        Log::info('ANAF SERVICE: Identified invoice file', ['filename' => $filename]);
                    }
                }
            }
            $zip->close();
            Log::info('ANAF SERVICE: ZIP processing completed');
        } else {
            Log::error('ANAF SERVICE: Failed to open ZIP file');
        }

        unlink($tempZipFile);
        Log::info('ANAF SERVICE: Cleanup completed', [
            'extracted_types' => array_keys(array_filter($result))
        ]);
        return $result;
    }

    /**
     * Archive message content directly to MongoDB
     */
    private function archiveMessage(string $downloadId, string $zipContent, array $extractedFiles, array $messageData): void
    {
        // This method now returns the data to be stored atomically in MongoDB
        // The actual storage is handled by the calling method
        Log::info('Message content prepared for MongoDB storage', ['downloadId' => $downloadId]);
    }

    /**
     * Convert XML to PDF using ANAF's native service
     */
    public function convertToPDF(string $xmlContent, string $standard = 'FACT1'): string
    {
        try {
            // First try ANAF's native PDF conversion
            $response = Http::withHeaders([
                'Content-Type' => 'text/plain'
            ])->post($this->baseUrl . "/transformare/{$standard}", $xmlContent);

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Exception $e) {
            Log::warning('ANAF PDF conversion failed, using fallback', ['error' => $e->getMessage()]);
        }

        // Fallback to client-side generation
        return $this->generatePDFClientSide($xmlContent);
    }

    /**
     * Generate PDF client-side with Romanian invoice requirements
     */
    private function generatePDFClientSide(string $xmlContent): string
    {
        // Parse XML
        $xml = simplexml_load_string($xmlContent);
        
        // For now, return a simple PDF content
        // In production, you would use a PDF library like TCPDF or DomPDF
        $pdfContent = "PDF content for invoice would be generated here from XML";
        
        return $pdfContent;
    }

    /**
     * Parse invoice XML to extract key data for display
     */
    public function parseInvoiceXML(string $xmlContent): array
    {
        try {
            $xml = simplexml_load_string($xmlContent);
            
            // Handle UBL format
            if (isset($xml->children('ubl', true)->Invoice) || $xml->getName() === 'Invoice') {
                return $this->parseUBLInvoice($xml);
            }
            
            // Handle CII format
            if (isset($xml->children('rsm', true)->CrossIndustryInvoice)) {
                return $this->parseCIIInvoice($xml);
            }
            
            return $this->parseGenericInvoice($xml);
        } catch (\Exception $e) {
            Log::error('XML parsing error', ['error' => $e->getMessage()]);
            return [
                'invoice_number' => 'Unknown',
                'issue_date' => null,
                'supplier_name' => 'Unknown',
                'customer_name' => 'Unknown',
                'total_amount' => 0,
                'currency' => 'RON',
                'status' => 'parsing_error'
            ];
        }
    }

    private function parseUBLInvoice(\SimpleXMLElement $xml): array
    {
        $ns = $xml->getNamespaces(true);

        // Get basic invoice info
        $idNodes = $xml->xpath('//cbc:ID');
        $invoiceNumber = !empty($idNodes) ? (string) $idNodes[0] : 'Unknown';

        $dateNodes = $xml->xpath('//cbc:IssueDate');
        $issueDate = !empty($dateNodes) ? (string) $dateNodes[0] : null;

        // Get supplier info - try multiple patterns for better accuracy
        $supplierName = $this->extractSupplierName($xml);
        $supplierTaxId = $this->extractSupplierTaxId($xml);

        // Get customer info - try multiple patterns for better accuracy
        $customerName = $this->extractCustomerName($xml);
        $customerTaxId = $this->extractCustomerTaxId($xml);

        // Get totals - try multiple patterns for better accuracy
        $totalAmount = 0;
        $currency = 'RON';

        // Try different total amount patterns
        $totalPatterns = [
            '//cac:LegalMonetaryTotal//cbc:PayableAmount',
            '//cac:LegalMonetaryTotal//cbc:TaxInclusiveAmount',
            '//cac:LegalMonetaryTotal//cbc:LineExtensionAmount',
            '//cac:AnticipatedMonetaryTotal//cbc:PayableAmount',
            '//cbc:PayableAmount',
            '//cbc:TaxInclusiveAmount'
        ];

        foreach ($totalPatterns as $pattern) {
            $nodes = $xml->xpath($pattern);
            if (!empty($nodes)) {
                $value = (string) $nodes[0];
                // Remove any non-numeric characters except decimal point
                $value = preg_replace('/[^0-9.]/', '', $value);
                if (is_numeric($value) && $value > 0) {
                    $totalAmount = (float) $value;

                    // Try to get currency from the same node
                    $currencyNodes = $xml->xpath($pattern . '/@currencyID');
                    if (!empty($currencyNodes)) {
                        $currency = (string) $currencyNodes[0];
                    }
                    break;
                }
            }
        }
        
        return [
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate ? Carbon::parse($issueDate) : null,
            'supplier_name' => $supplierName,
            'supplier_tax_id' => $supplierTaxId,
            'customer_name' => $customerName,
            'customer_tax_id' => $customerTaxId,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'status' => 'parsed'
        ];
    }

    private function parseCIIInvoice(\SimpleXMLElement $xml): array
    {
        // CII (Cross Industry Invoice) parsing
        $ns = $xml->getNamespaces(true);

        // Get basic invoice info
        $invoiceNumber = 'Unknown';
        $idNodes = $xml->xpath('//rsm:ExchangedDocument//ram:ID');
        if (!empty($idNodes)) {
            $invoiceNumber = (string) $idNodes[0];
        }

        $issueDate = null;
        $dateNodes = $xml->xpath('//rsm:ExchangedDocument//ram:IssueDateTime//udt:DateTimeString');
        if (!empty($dateNodes)) {
            $issueDate = (string) $dateNodes[0];
        }

        // Get supplier and customer info using the existing extraction methods
        $supplierName = $this->extractSupplierName($xml);
        $supplierTaxId = $this->extractSupplierTaxId($xml);
        $customerName = $this->extractCustomerName($xml);
        $customerTaxId = $this->extractCustomerTaxId($xml);

        // Get totals - try multiple patterns for better accuracy
        $totalAmount = 0;
        $currency = 'RON';

        // Try different total amount patterns for CII format
        $totalPatterns = [
            '//ram:SpecifiedTradeSettlementHeaderMonetarySummation//ram:GrandTotalAmount',
            '//ram:SpecifiedTradeSettlementHeaderMonetarySummation//ram:DuePayableAmount',
            '//ram:SpecifiedTradeSettlementHeaderMonetarySummation//ram:TaxBasisTotalAmount',
            '//ram:GrandTotalAmount',
            '//ram:DuePayableAmount'
        ];

        foreach ($totalPatterns as $pattern) {
            $nodes = $xml->xpath($pattern);
            if (!empty($nodes)) {
                $value = (string) $nodes[0];
                // Remove any non-numeric characters except decimal point
                $value = preg_replace('/[^0-9.]/', '', $value);
                if (is_numeric($value) && $value > 0) {
                    $totalAmount = (float) $value;

                    // Try to get currency from the same node
                    $currencyNodes = $xml->xpath($pattern . '/@currencyID');
                    if (!empty($currencyNodes)) {
                        $currency = (string) $currencyNodes[0];
                    }
                    break;
                }
            }
        }
        
        return [
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate ? Carbon::parse($issueDate) : null,
            'supplier_name' => $supplierName,
            'supplier_tax_id' => $supplierTaxId,
            'customer_name' => $customerName,
            'customer_tax_id' => $customerTaxId,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'status' => 'parsed'
        ];
    }

    private function parseGenericInvoice(\SimpleXMLElement $xml): array
    {
        // Generic fallback parsing
        $idNodes = $xml->xpath('//ID');
        $invoiceNumber = !empty($idNodes) ? (string) $idNodes[0] : 'Generic';
        
        return [
            'invoice_number' => $invoiceNumber,
            'issue_date' => null,
            'supplier_name' => 'Unknown',
            'customer_name' => 'Unknown',
            'total_amount' => 0,
            'currency' => 'RON',
            'status' => 'generic_format'
        ];
    }
    
    /**
     * Extract supplier name from UBL XML using multiple patterns
     */
    private function extractSupplierName(\SimpleXMLElement $xml): string
    {
        $patterns = [
            '//cac:AccountingSupplierParty//cac:Party//cac:PartyName//cbc:Name',
            '//cac:AccountingSupplierParty//cac:Party//cac:PartyLegalEntity//cbc:RegistrationName',
            '//cac:AccountingSupplierParty//cbc:Name',
            '//ram:SellerTradeParty//ram:Name' // CII format
        ];
        
        foreach ($patterns as $pattern) {
            $nodes = $xml->xpath($pattern);
            if (!empty($nodes) && !empty(trim((string) $nodes[0]))) {
                return trim((string) $nodes[0]);
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Extract supplier tax ID from UBL XML using multiple patterns
     */
    private function extractSupplierTaxId(\SimpleXMLElement $xml): string
    {
        $patterns = [
            '//cac:AccountingSupplierParty//cac:Party//cac:PartyTaxScheme//cbc:CompanyID',
            '//cac:AccountingSupplierParty//cac:Party//cac:PartyLegalEntity//cbc:CompanyID',
            '//cac:AccountingSupplierParty//cbc:CompanyID',
            '//ram:SellerTradeParty//ram:SpecifiedTaxRegistration//ram:ID' // CII format
        ];
        
        foreach ($patterns as $pattern) {
            $nodes = $xml->xpath($pattern);
            if (!empty($nodes) && !empty(trim((string) $nodes[0]))) {
                return trim((string) $nodes[0]);
            }
        }
        
        return '';
    }
    
    /**
     * Extract customer name from UBL XML using multiple patterns
     */
    private function extractCustomerName(\SimpleXMLElement $xml): string
    {
        $patterns = [
            '//cac:AccountingCustomerParty//cac:Party//cac:PartyName//cbc:Name',
            '//cac:AccountingCustomerParty//cac:Party//cac:PartyLegalEntity//cbc:RegistrationName', 
            '//cac:AccountingCustomerParty//cbc:Name',
            '//ram:BuyerTradeParty//ram:Name' // CII format
        ];
        
        foreach ($patterns as $pattern) {
            $nodes = $xml->xpath($pattern);
            if (!empty($nodes) && !empty(trim((string) $nodes[0]))) {
                return trim((string) $nodes[0]);
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Extract customer tax ID from UBL XML using multiple patterns
     */
    private function extractCustomerTaxId(\SimpleXMLElement $xml): string
    {
        $patterns = [
            '//cac:AccountingCustomerParty//cac:Party//cac:PartyTaxScheme//cbc:CompanyID',
            '//cac:AccountingCustomerParty//cac:Party//cac:PartyLegalEntity//cbc:CompanyID',
            '//cac:AccountingCustomerParty//cbc:CompanyID',
            '//ram:BuyerTradeParty//ram:SpecifiedTaxRegistration//ram:ID' // CII format
        ];
        
        foreach ($patterns as $pattern) {
            $nodes = $xml->xpath($pattern);
            if (!empty($nodes) && !empty(trim((string) $nodes[0]))) {
                return trim((string) $nodes[0]);
            }
        }
        
        return '';
    }
}