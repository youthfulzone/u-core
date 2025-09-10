<?php

namespace App\Services;

use App\Models\EfacturaToken;
use App\Models\EfacturaInvoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EfacturaApiService
{
    private const ANAF_EFACTURA_BASE_URL = 'https://api.anaf.ro/prod/FCTEL/rest';
    private const ANAF_EFACTURA_TEST_URL = 'https://api.anaf.ro/test/FCTEL/rest';

    public function __construct(
        private AnafOAuthService $oauthService,
        private string $environment = 'sandbox'
    ) {}

    public function uploadInvoice(string $cui, string $xmlContent, string $filename = null): array
    {
        $token = $this->oauthService->getValidToken($cui);

        if (!$token) {
            throw new \Exception("No valid token found for CUI: {$cui}");
        }

        $baseUrl = $this->environment === 'production' 
            ? self::ANAF_EFACTURA_BASE_URL 
            : self::ANAF_EFACTURA_TEST_URL;

        // Prepare multipart form data
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token->access_token}",
        ])->attach(
            'file', $xmlContent, $filename ?? 'invoice.xml'
        )->post("{$baseUrl}/upload");

        if (!$response->successful()) {
            Log::error('E-factura upload failed', [
                'cui' => $cui,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            throw new \Exception('Failed to upload invoice: ' . $response->body());
        }

        $responseData = $response->json();

        // Store the invoice record
        EfacturaInvoice::create([
            'cui' => $cui,
            'company_id' => $token->company_id,
            'invoice_id' => $responseData['invoice_id'] ?? null,
            'upload_index' => $responseData['upload_index'] ?? null,
            'xml_content' => $xmlContent,
            'upload_status' => 'uploaded',
            'anaf_response' => $responseData,
            'uploaded_at' => Carbon::now(),
            'file_size' => strlen($xmlContent),
            'original_filename' => $filename,
            'checksum' => md5($xmlContent)
        ]);

        return $responseData;
    }

    public function getUploadState(string $cui, string $uploadIndex): array
    {
        $token = $this->oauthService->getValidToken($cui);

        if (!$token) {
            throw new \Exception("No valid token found for CUI: {$cui}");
        }

        $baseUrl = $this->environment === 'production' 
            ? self::ANAF_EFACTURA_BASE_URL 
            : self::ANAF_EFACTURA_TEST_URL;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token->access_token}",
        ])->get("{$baseUrl}/stareMesaj", [
            'id_incarcare' => $uploadIndex
        ]);

        if (!$response->successful()) {
            Log::error('E-factura state check failed', [
                'cui' => $cui,
                'upload_index' => $uploadIndex,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            throw new \Exception('Failed to get upload state: ' . $response->body());
        }

        $responseData = $response->json();

        // Update the invoice record
        $invoice = EfacturaInvoice::where('cui', $cui)
            ->where('upload_index', $uploadIndex)
            ->first();

        if ($invoice) {
            $invoice->update([
                'status' => $responseData['stare'] ?? 'unknown',
                'anaf_response' => array_merge($invoice->anaf_response ?? [], $responseData),
                'processed_at' => Carbon::now()
            ]);
        }

        return $responseData;
    }

    public function downloadInvoice(string $cui, string $invoiceId): array
    {
        $token = $this->oauthService->getValidToken($cui);

        if (!$token) {
            throw new \Exception("No valid token found for CUI: {$cui}");
        }

        $baseUrl = $this->environment === 'production' 
            ? self::ANAF_EFACTURA_BASE_URL 
            : self::ANAF_EFACTURA_TEST_URL;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token->access_token}",
        ])->get("{$baseUrl}/descarcare", [
            'id' => $invoiceId
        ]);

        if (!$response->successful()) {
            Log::error('E-factura download failed', [
                'cui' => $cui,
                'invoice_id' => $invoiceId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            throw new \Exception('Failed to download invoice: ' . $response->body());
        }

        return [
            'content' => $response->body(),
            'content_type' => $response->header('Content-Type'),
            'filename' => $this->extractFilenameFromHeaders($response->headers())
        ];
    }

    public function getInvoiceList(string $cui, array $filters = []): array
    {
        $token = $this->oauthService->getValidToken($cui);

        if (!$token) {
            throw new \Exception("No valid token found for CUI: {$cui}");
        }

        $baseUrl = $this->environment === 'production' 
            ? self::ANAF_EFACTURA_BASE_URL 
            : self::ANAF_EFACTURA_TEST_URL;

        $params = array_merge([
            'cif' => $cui
        ], $filters);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token->access_token}",
        ])->get("{$baseUrl}/listaMesajePaginat", $params);

        if (!$response->successful()) {
            Log::error('E-factura list failed', [
                'cui' => $cui,
                'filters' => $filters,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            throw new \Exception('Failed to get invoice list: ' . $response->body());
        }

        return $response->json();
    }

    public function validateInvoiceXml(string $xmlContent): array
    {
        // Basic XML validation
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            return [
                'valid' => false,
                'errors' => array_map(fn($error) => $error->message, $errors)
            ];
        }

        // Additional business rules validation can be added here
        $validationErrors = [];

        // Check for required elements
        $requiredElements = ['Invoice', 'cbc:ID', 'cac:AccountingSupplierParty', 'cac:AccountingCustomerParty'];
        
        foreach ($requiredElements as $element) {
            if (!$xml->xpath("//{$element}")) {
                $validationErrors[] = "Missing required element: {$element}";
            }
        }

        return [
            'valid' => empty($validationErrors),
            'errors' => $validationErrors
        ];
    }

    private function extractFilenameFromHeaders(array $headers): ?string
    {
        $contentDisposition = $headers['Content-Disposition'][0] ?? '';
        
        if (preg_match('/filename="?([^"]+)"?/', $contentDisposition, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function bulkUpload(string $cui, array $invoices): array
    {
        $results = [];
        
        foreach ($invoices as $index => $invoice) {
            try {
                $result = $this->uploadInvoice(
                    $cui, 
                    $invoice['xml_content'], 
                    $invoice['filename'] ?? "invoice_{$index}.xml"
                );
                
                $results[] = [
                    'index' => $index,
                    'success' => true,
                    'data' => $result
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'index' => $index,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}
