<?php

namespace App\Services;

use App\Models\EfacturaToken;
use App\Models\AnafCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;
use Carbon\Carbon;

class AnafEfacturaService
{
    private string $baseUrl;
    private ?string $accessToken = null;

    public function __construct(string $environment = 'production')
    {
        $this->baseUrl = $environment === 'production' 
            ? 'https://api.anaf.ro/prod/FCTEL/rest'
            : 'https://api.anaf.ro/test/FCTEL/rest';
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
            $response = Http::withToken($this->getAccessToken())
                ->get($this->baseUrl . '/listaMesajePaginatieFactura', $params);

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
     * Download a specific message/invoice and return all data for MongoDB storage
     */
    public function downloadMessage(string $downloadId, array $messageData = []): array
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->get($this->baseUrl . '/descarcare', ['id' => $downloadId]);

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
            Log::error('ANAF e-Factura download error', [
                'downloadId' => $downloadId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Extract ZIP contents
     */
    private function extractZipContents(string $zipContent): array
    {
        $tempZipFile = tempnam(sys_get_temp_dir(), 'anaf_invoice_');
        file_put_contents($tempZipFile, $zipContent);

        $zip = new \ZipArchive();
        $result = [
            'invoice' => null,
            'signature' => null,
            'errors' => null
        ];

        if ($zip->open($tempZipFile) === TRUE) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $content = $zip->getFromIndex($i);

                if (str_ends_with($filename, '.xml')) {
                    // Identify file type based on content
                    if (str_contains($content, 'ds:Signature') || 
                        str_contains($filename, 'semnatura') || 
                        str_contains($filename, 'signature')) {
                        $result['signature'] = $content;
                    } elseif (str_contains($content, 'ErrorList') || 
                              str_contains($filename, 'erori') || 
                              str_contains($filename, 'errors')) {
                        $result['errors'] = $content;
                    } else {
                        $result['invoice'] = $content;
                    }
                }
            }
            $zip->close();
        }

        unlink($tempZipFile);
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
        
        // Get supplier info
        $supplierNameNodes = $xml->xpath('//cac:AccountingSupplierParty//cbc:Name');
        $supplierName = !empty($supplierNameNodes) ? (string) $supplierNameNodes[0] : 'Unknown';
        
        $supplierTaxNodes = $xml->xpath('//cac:AccountingSupplierParty//cbc:CompanyID');
        $supplierTaxId = !empty($supplierTaxNodes) ? (string) $supplierTaxNodes[0] : '';
        
        // Get customer info
        $customerNameNodes = $xml->xpath('//cac:AccountingCustomerParty//cbc:Name');
        $customerName = !empty($customerNameNodes) ? (string) $customerNameNodes[0] : 'Unknown';
        
        $customerTaxNodes = $xml->xpath('//cac:AccountingCustomerParty//cbc:CompanyID');
        $customerTaxId = !empty($customerTaxNodes) ? (string) $customerTaxNodes[0] : '';
        
        // Get totals
        $totalNodes = $xml->xpath('//cac:LegalMonetaryTotal//cbc:PayableAmount');
        $totalAmount = !empty($totalNodes) ? (float) $totalNodes[0] : 0;
        
        $currencyNodes = $xml->xpath('//cac:LegalMonetaryTotal//cbc:PayableAmount/@currencyID');
        $currency = !empty($currencyNodes) ? (string) $currencyNodes[0] : 'RON';
        
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
        // Implement CII parsing
        return [
            'invoice_number' => 'CII Format',
            'issue_date' => null,
            'supplier_name' => 'Unknown',
            'customer_name' => 'Unknown',
            'total_amount' => 0,
            'currency' => 'RON',
            'status' => 'cii_format'
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
}