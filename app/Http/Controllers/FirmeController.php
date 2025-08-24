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

        // Get filtered pending/processing items
        $allQueue = CompanyQueue::whereIn('status', ['pending', 'processing'])
            ->orderBy('created_at')
            ->get();

        // Filter to only show CUIs with 6-9 digits (valid CUI length)
        $filteredQueue = $allQueue->filter(function ($item) {
            return strlen($item->cui) >= 6 &&
                   strlen($item->cui) <= 9 &&
                   preg_match('/^[0-9]+$/', $item->cui);
        })->values(); // Reset array keys

        // Get approved companies
        $companies = Company::orderBy('denumire')->get();

        // Combine pending companies first, then regular companies
        $allItems = collect();

        // Add pending/processing items first (with type marker)
        foreach ($filteredQueue as $queueItem) {
            $allItems->push([
                'id' => $queueItem->_id,
                'cui' => $queueItem->cui,
                'denumire' => $queueItem->company_name ?? 'În procesare...',
                'status' => $queueItem->status,
                'type' => 'pending',
                'created_at' => $queueItem->created_at,
                'updated_at' => $queueItem->updated_at,
            ]);
        }

        // Add approved companies
        foreach ($companies as $company) {
            $allItems->push([
                'id' => $company->_id,
                'cui' => $company->cui,
                'denumire' => $company->denumire,
                'status' => 'approved',
                'type' => 'company',
                'created_at' => $company->created_at,
                'updated_at' => $company->updated_at,
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
            'total_companies' => $companies->count(),
            'pending_queue' => $filteredQueue->where('status', 'pending')->count(),
            'processing_queue' => $filteredQueue->where('status', 'processing')->count(),
            'approved_today' => CompanyQueue::where('status', 'approved')
                ->whereDate('updated_at', Carbon::today())
                ->count(),
            'rejected_today' => CompanyQueue::where('status', 'rejected')
                ->whereDate('updated_at', Carbon::today())
                ->count(),
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
            $item = CompanyQueue::find($itemId);

            if (! $item) {
                return redirect()->back()->with('error', 'Element nu a fost găsit în coadă.');
            }

            $success = $this->companyService->approveCompany($item->cui);

            if ($success) {
                Log::info('Company approved and added', [
                    'cui' => $item->cui,
                    'item_id' => $itemId,
                    'user_id' => Auth::id(),
                ]);

                return redirect()->back()->with('success',
                    "Compania cu CUI {$item->cui} a fost aprobată și adăugată."
                );
            } else {
                return redirect()->back()->with('error',
                    'Nu s-a putut aproba compania - datele lipsesc sau sunt incomplete.'
                );
            }
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
            $item = CompanyQueue::find($itemId);

            if (! $item) {
                return redirect()->back()->with('error', 'Element nu a fost găsit în coadă.');
            }

            $success = $this->companyService->rejectCompany($item->cui);

            if ($success) {
                Log::info('Company rejected', [
                    'cui' => $item->cui,
                    'item_id' => $itemId,
                    'user_id' => Auth::id(),
                ]);

                return redirect()->back()->with('success',
                    "Compania cu CUI {$item->cui} a fost respinsă."
                );
            } else {
                return redirect()->back()->with('error',
                    'Nu s-a putut respinge compania - elementul nu a fost găsit.'
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
}
