<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Models\Spv\SpvMessage;
use App\Models\Spv\SpvRequest;
use App\Services\AnafSpvService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SpvRequestsController extends Controller
{
    public function __construct(
        private AnafSpvService $anafSpvService
    ) {}

    public function index()
    {
        // Get unique CIFs from messages for selection with company names
        $availableCifs = SpvMessage::select('cif', 'cui', 'detalii')
            ->whereNotNull('cif')
            ->where('cif', '!=', '')
            ->distinct()
            ->orderBy('cif')
            ->get()
            ->map(function ($message) {
                return [
                    'cif' => $message->cif,
                    'cui' => $message->cui ?? $message->cif,
                    'company_name' => $this->extractCompanyName($message->detalii ?? ''),
                ];
            })
            ->unique('cif')
            ->values()
            ->toArray();

        // Get paginated requests with user information
        $requests = SpvRequest::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Get session status for breadcrumb status indicator
        $sessionStatus = $this->anafSpvService->getSessionStatus();

        return Inertia::render('spv/Requests', [
            'requests' => $requests,
            'availableCifs' => $availableCifs,
            'documentTypes' => [
                'Fisa Rol' => 'Fisa Rol',
            ],
            'sessionActive' => $sessionStatus['active'],
            'authenticationStatusText' => $sessionStatus['authentication_status'] ?? 'not_authenticated',
        ]);
    }

    public function makeRequest(Request $request)
    {
        $request->validate([
            'cif' => 'required|string',
            'document_type' => 'required|string|in:Fisa Rol',
        ]);

        // Get company name from messages
        $message = SpvMessage::where('cif', $request->cif)->first();
        $companyName = $message ? $this->extractCompanyName($message->detalii ?? '') : '';

        // Create request record
        $spvRequest = SpvRequest::create([
            'user_id' => auth()->id(),
            'cif' => $request->cif,
            'cui' => $message ? ($message->cui ?? $request->cif) : $request->cif,
            'document_type' => $request->document_type,
            'tip' => $request->document_type,
            'company_name' => $companyName,
            'status' => 'pending',
        ]);

        // Make request to ANAF
        try {
            $anafResponse = $this->makeAnafRequest($request->cif, $request->document_type);

            $spvRequest->update([
                'status' => 'completed',
                'response_data' => $anafResponse,
                'processed_at' => now(),
            ]);

            return back()->with('success', 'Cererea a fost trimisă cu succes către ANAF.');
        } catch (\Exception $e) {
            $spvRequest->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            return back()->with('error', 'Eroare la trimiterea cererii: '.$e->getMessage());
        }
    }

    private function makeAnafRequest($cif, $documentType)
    {
        // Use the ANAF service to make the actual request
        return $this->anafSpvService->makeDocumentRequest($documentType, [
            'cui' => $cif,
        ]);
    }

    private function extractCompanyName(string $detalii): string
    {
        // Try to extract company name from detalii field
        // Common patterns: "NUME COMPANIE - details" or "Company Name, CIF: ..."
        $lines = explode("\n", $detalii);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Look for lines that might contain company names
            if (preg_match('/^([A-Z\s&\-\.]+)(\s*[-,]|\s*CIF|\s*CUI)/i', $line, $matches)) {
                return trim($matches[1]);
            }
        }

        // Fallback: return first non-empty line
        return ! empty($lines[0]) ? trim($lines[0]) : '';
    }
}
