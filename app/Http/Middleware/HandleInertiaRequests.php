<?php

namespace App\Http\Middleware;

use App\Services\AnafSpvService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        // Get ANAF status globally for header
        $anafData = [];
        try {
            $spvService = app(AnafSpvService::class);
            $sessionStatus = $spvService->getSessionStatus();
            $apiCallStatus = $spvService->getApiCallStatus();

            $anafData = [
                'sessionActive' => $sessionStatus['active'] ?? false,
                'apiCallStatus' => $apiCallStatus,
                'authenticationStatusText' => $sessionStatus['authentication_status'] ?? 'not_authenticated',
            ];
        } catch (\Exception $e) {
            // Fallback if service fails
            $anafData = [
                'sessionActive' => false,
                'apiCallStatus' => null,
                'authenticationStatusText' => 'not_authenticated',
            ];
        }

        // Get Targetare API status
        $targetareData = [];
        try {
            $targetareService = app(\App\Services\TargetareApiService::class);
            $remainingRequests = $targetareService->getRemainingRequests();
            
            $targetareData = [
                'remainingRequests' => $remainingRequests,
                'apiAvailable' => $remainingRequests !== null,
            ];
        } catch (\Exception $e) {
            // Fallback if service fails
            $targetareData = [
                'remainingRequests' => null,
                'apiAvailable' => false,
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'ziggy' => fn (): array => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
            ],
            ...$anafData,
            'targetare' => $targetareData,
        ];
    }
}
