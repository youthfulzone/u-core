<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyQueue;
use App\Services\AnafCompanyService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class FirmeController extends Controller
{
    public function __construct(
        private AnafCompanyService $companyService
    ) {}

    public function index(Request $request): Response
    {
        $perPage = 15;
        $page = $request->get('page', 1);

        // Get all companies
        $companies = Company::all();

        // Filter to only show CUIs with 6-9 digits (valid CUI length) and sort alphabetically by company name
        $filteredCompanies = $companies->filter(function ($item) {
            return strlen($item->cui) >= 6 &&
                   strlen($item->cui) <= 9 &&
                   preg_match('/^[0-9]+$/', $item->cui);
        })->sortBy(function ($item) {
            // Sort alphabetically by company name (denumire)
            // Handle cases where denumire might be null or "Se încarcă..."
            $name = $item->denumire ?? '';
            if ($name === 'Se încarcă...' || $name === '') {
                // Put companies without names at the end
                return 'zzz_' . $item->cui;
            }
            return strtolower($name);
        })->values();

        // Build items list
        $allItems = collect();

        foreach ($filteredCompanies as $company) {
            $allItems->push([
                'id' => $company->_id,
                'cui' => $company->cui,
                'denumire' => $company->denumire ?? 'Se încarcă...',
                'status' => $company->status ?? 'active',
                'type' => 'company',
                'created_at' => $company->created_at,
                'updated_at' => $company->updated_at,
                'synced_at' => $company->synced_at,
                'locked' => $company->locked ?? false,
                'source_api' => $company->source_api ?? null,
                'tax_category' => $company->tax_category ?? null,
                'employees_current' => $company->employees_current ?? null,
                'vat' => $company->vat ?? $company->vat_valid ?? false,
                'split_vat' => $company->split_vat ?? false,
                'checkout_vat' => $company->checkout_vat ?? false,
                'manual_added' => $company->manual_added ?? false,
            ]);
        }

        // Manual pagination
        $total = $allItems->count();
        $lastPage = ceil($total / $perPage);
        $currentPage = min($page, $lastPage ?: 1);
        $offset = ($currentPage - 1) * $perPage;

        $paginatedItems = $allItems->slice($offset, $perPage)->values();

        $paginationData = [
            'data' => $paginatedItems,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];

        // Calculate stats
        $stats = [
            'total_companies' => $filteredCompanies->count(),
            'pending_data' => $filteredCompanies->where('status', 'pending_data')->count(),
            'processing' => $filteredCompanies->where('status', 'processing')->count(),
            'active' => $filteredCompanies->where('status', 'active')->count(),
            'data_not_found' => $filteredCompanies->where('status', 'data_not_found')->count(),
            'failed' => $filteredCompanies->where('status', 'failed')->count(),
        ];

        return Inertia::render('firme/Index', [
            'companies' => $paginationData,
            'stats' => $stats,
        ]);
    }

    public function processQueue(Request $request): RedirectResponse
    {
        try {
            // Process all pending items in queue
            $pendingItems = CompanyQueue::where('status', 'pending')->get();
            $processed = 0;

            foreach ($pendingItems as $item) {
                $result = $this->companyService->processCompanyQueue($item->cui);
                if ($result['success']) {
                    $processed++;
                }
            }

            Log::info('Company queue batch processed', [
                'processed_count' => $processed,
                'total_pending' => $pendingItems->count(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('success',
                "S-au procesat {$processed} din {$pendingItems->count()} CUI-uri din coadă."
            );
        } catch (\Exception $e) {
            Log::error('Failed to process company queue batch', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error',
                'Eroare la procesarea cozii: '.$e->getMessage()
            );
        }
    }

    public function approve(Request $request): RedirectResponse
    {
        $request->validate([
            'item_id' => 'required|string',
        ]);

        try {
            $itemId = $request->input('item_id');
            $company = Company::find($itemId);

            if (! $company) {
                return redirect()->back()->with('error', 'Compania nu a fost găsită.');
            }

            // Approve means keeping the company and marking it as approved
            $company->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            Log::info('Company approved', [
                'cui' => $company->cui,
                'item_id' => $itemId,
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('success',
                "Compania cu CUI {$company->cui} a fost aprobată."
            );
        } catch (\Exception $e) {
            Log::error('Failed to approve company', [
                'item_id' => $request->input('item_id'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error',
                'Eroare la aprobarea companiei: '.$e->getMessage()
            );
        }
    }

    public function reject(Request $request): RedirectResponse
    {
        $request->validate([
            'item_id' => 'required|string',
        ]);

        try {
            $itemId = $request->input('item_id');
            $company = Company::find($itemId);

            if (! $company) {
                return redirect()->back()->with('error', 'Compania nu a fost găsită.');
            }

            $cui = $company->cui;
            $success = $this->companyService->rejectCompany($cui);

            if ($success) {
                Log::info('Company rejected and deleted', [
                    'cui' => $cui,
                    'item_id' => $itemId,
                    'user_id' => Auth::id(),
                ]);

                return redirect()->back()->with('success',
                    "Compania cu CUI {$cui} a fost respinsă și ștearsă."
                );
            } else {
                return redirect()->back()->with('error',
                    'Nu s-a putut respinge compania.'
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to reject company', [
                'item_id' => $request->input('item_id'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error',
                'Eroare la respingerea companiei: '.$e->getMessage()
            );
        }
    }

    public function massAction(Request $request): RedirectResponse
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'item_ids' => 'required|array',
            'item_ids.*' => 'required|string',
        ]);

        try {
            $action = $request->input('action');
            $itemIds = $request->input('item_ids');

            $items = CompanyQueue::whereIn('_id', $itemIds)->get();
            $successCount = 0;
            $totalCount = $items->count();

            foreach ($items as $item) {
                if ($action === 'approve') {
                    $success = $this->companyService->approveCompany($item->cui);
                } else {
                    $success = $this->companyService->rejectCompany($item->cui);
                }

                if ($success) {
                    $successCount++;
                }
            }

            Log::info('Mass company action completed', [
                'action' => $action,
                'success_count' => $successCount,
                'total_count' => $totalCount,
                'user_id' => Auth::id(),
            ]);

            $actionText = $action === 'approve' ? 'aprobate' : 'respinse';

            return redirect()->back()->with('success',
                "S-au {$actionText} {$successCount} din {$totalCount} companii selectate."
            );
        } catch (\Exception $e) {
            Log::error('Failed to process mass company action', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error',
                'Eroare la acțiunea în masă: '.$e->getMessage()
            );
        }
    }

    public function getStatus(Request $request)
    {
        $perPage = 15;
        $page = $request->get('page', 1);

        // Get all companies
        $companies = Company::all();

        // Filter to only show CUIs with 6-9 digits (valid CUI length) and sort alphabetically by company name
        $filteredCompanies = $companies->filter(function ($item) {
            return strlen($item->cui) >= 6 &&
                   strlen($item->cui) <= 9 &&
                   preg_match('/^[0-9]+$/', $item->cui);
        })->sortBy(function ($item) {
            // Sort alphabetically by company name (denumire)
            // Handle cases where denumire might be null or "Se încarcă..."
            $name = $item->denumire ?? '';
            if ($name === 'Se încarcă...' || $name === '') {
                // Put companies without names at the end
                return 'zzz_' . $item->cui;
            }
            return strtolower($name);
        })->values();

        // Build items list
        $allItems = collect();

        foreach ($filteredCompanies as $company) {
            $allItems->push([
                'id' => $company->_id,
                'cui' => $company->cui,
                'denumire' => $company->denumire ?? 'Se încarcă...',
                'status' => $company->status ?? 'active',
                'type' => 'company',
                'created_at' => $company->created_at,
                'updated_at' => $company->updated_at,
                'synced_at' => $company->synced_at,
                'locked' => $company->locked ?? false,
                'source_api' => $company->source_api ?? null,
                'tax_category' => $company->tax_category ?? null,
                'employees_current' => $company->employees_current ?? null,
                'vat' => $company->vat ?? $company->vat_valid ?? false,
                'split_vat' => $company->split_vat ?? false,
                'checkout_vat' => $company->checkout_vat ?? false,
                'manual_added' => $company->manual_added ?? false,
            ]);
        }

        // Manual pagination
        $total = $allItems->count();
        $lastPage = ceil($total / $perPage);
        $currentPage = min($page, $lastPage ?: 1);
        $offset = ($currentPage - 1) * $perPage;

        $paginatedItems = $allItems->slice($offset, $perPage)->values();

        $paginationData = [
            'data' => $paginatedItems,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];

        // Calculate stats
        $stats = [
            'total_companies' => $filteredCompanies->count(),
            'pending_data' => $filteredCompanies->where('status', 'pending_data')->count(),
            'processing' => $filteredCompanies->where('status', 'processing')->count(),
            'active' => $filteredCompanies->where('status', 'active')->count(),
            'data_not_found' => $filteredCompanies->where('status', 'data_not_found')->count(),
            'failed' => $filteredCompanies->where('status', 'failed')->count(),
        ];

        return response()->json([
            'companies' => $paginationData,
            'stats' => $stats,
        ]);
    }

    public function processNext(Request $request)
    {
        try {
            $result = $this->companyService->processNextPendingCompany();
            
            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Failed to process next company', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process next company: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function verifyCompany(Request $request): RedirectResponse
    {
        $request->validate([
            'item_id' => 'required|string',
        ]);

        try {
            $itemId = $request->input('item_id');
            $company = Company::find($itemId);

            if (! $company) {
                return redirect()->back()->with('error', 'Compania nu a fost găsită.');
            }

            // Re-fetch company data without changing approval status
            // Only reset synced_at to trigger data refresh
            $company->update([
                'synced_at' => null,
            ]);

            // Process this specific company
            $result = $this->companyService->processSpecificCompany($company->cui);

            Log::info('Company verification requested', [
                'cui' => $company->cui,
                'item_id' => $itemId,
                'result' => $result,
                'user_id' => Auth::id(),
            ]);

            if ($result['success'] ?? false) {
                return redirect()->back()->with('success', 
                    "Compania cu CUI {$company->cui} a fost verificată cu succes."
                );
            } else {
                return redirect()->back()->with('error', 
                    'Nu s-au putut obține informații actualizate pentru această companie.'
                );
            }

        } catch (\Exception $e) {
            Log::error('Failed to verify company', [
                'item_id' => $request->input('item_id'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error', 
                'Eroare la verificarea companiei: ' . $e->getMessage()
            );
        }
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->validate([
            'item_id' => 'required|string',
        ]);

        try {
            $itemId = $request->input('item_id');
            $company = Company::find($itemId);

            if (! $company) {
                return redirect()->back()->with('error', 'Compania nu a fost găsită.');
            }

            $company->update([
                'locked' => true,
                'locked_at' => now(),
                'locked_by' => Auth::id(),
            ]);

            Log::info('Company locked', [
                'cui' => $company->cui,
                'item_id' => $itemId,
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('success',
                "Compania cu CUI {$company->cui} a fost blocată."
            );
        } catch (\Exception $e) {
            Log::error('Failed to lock company', [
                'item_id' => $request->input('item_id'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error',
                'Eroare la blocarea companiei: '.$e->getMessage()
            );
        }
    }

    public function unlock(Request $request): RedirectResponse
    {
        $request->validate([
            'item_id' => 'required|string',
        ]);

        try {
            $itemId = $request->input('item_id');
            $company = Company::find($itemId);

            if (! $company) {
                return redirect()->back()->with('error', 'Compania nu a fost găsită.');
            }

            $company->update([
                'locked' => false,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            Log::info('Company unlocked', [
                'cui' => $company->cui,
                'item_id' => $itemId,
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('success',
                "Compania cu CUI {$company->cui} a fost deblocată."
            );
        } catch (\Exception $e) {
            Log::error('Failed to unlock company', [
                'item_id' => $request->input('item_id'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error',
                'Eroare la deblocarea companiei: '.$e->getMessage()
            );
        }
    }

    public function addCompany(Request $request): RedirectResponse
    {
        $request->validate([
            'cui' => 'required|string|min:6|max:9|regex:/^[0-9]+$/',
        ]);

        try {
            $cui = $request->input('cui');
            
            // Check if company already exists
            $existingCompany = Company::where('cui', $cui)->first();
            if ($existingCompany) {
                return redirect()->back()->with('error', 
                    "Compania cu CUI {$cui} există deja în sistem."
                );
            }

            // Create new company with manual_added flag and auto-approve it
            $company = Company::create([
                'cui' => $cui,
                'denumire' => 'Se încarcă...',
                'status' => 'approved',
                'manual_added' => true,
                'added_by' => Auth::id(),
                'approved_at' => now(),
                'approved_by' => Auth::id(),
                'synced_at' => null,
            ]);

            Log::info('Company manually added', [
                'cui' => $cui,
                'company_id' => $company->_id,
                'user_id' => Auth::id(),
            ]);

            // Process the company immediately to fetch data
            $this->companyService->processSpecificCompany($cui);

            return redirect()->back()->with('success',
                "Compania cu CUI {$cui} a fost adăugată și se procesează."
            );

        } catch (\Exception $e) {
            Log::error('Failed to add company manually', [
                'cui' => $request->input('cui'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error',
                'Eroare la adăugarea companiei: '.$e->getMessage()
            );
        }
    }

    public function deleteCompany(Request $request): RedirectResponse
    {
        $request->validate([
            'item_id' => 'required|string',
        ]);

        try {
            $itemId = $request->input('item_id');
            $company = Company::find($itemId);

            if (! $company) {
                return redirect()->back()->with('error', 'Compania nu a fost găsită.');
            }

            // Only allow deletion of manually added companies
            if (!$company->manual_added) {
                return redirect()->back()->with('error', 
                    'Doar companiile adăugate manual pot fi șterse.'
                );
            }

            $cui = $company->cui;
            $company->delete();

            Log::info('Manually added company deleted', [
                'cui' => $cui,
                'item_id' => $itemId,
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('success',
                "Compania cu CUI {$cui} a fost ștearsă."
            );

        } catch (\Exception $e) {
            Log::error('Failed to delete company', [
                'item_id' => $request->input('item_id'),
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->with('error',
                'Eroare la ștergerea companiei: '.$e->getMessage()
            );
        }
    }
}
