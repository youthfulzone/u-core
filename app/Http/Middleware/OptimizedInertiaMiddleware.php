<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

/**
 * Laravel 12 optimized Inertia middleware with caching and performance improvements
 */
class OptimizedInertiaMiddleware extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        // Cache the asset version to avoid filesystem calls
        return Cache::remember('inertia.asset.version', 300, function () {
            if (file_exists($manifest = public_path('build/manifest.json'))) {
                return md5_file($manifest);
            }
            
            return parent::version(request());
        });
    }

    /**
     * Defines the props that are shared by default.
     */
    public function share(Request $request): array
    {
        $baseProps = parent::share($request);
        
        return array_merge($baseProps, [
            'auth' => fn () => $this->getAuthData($request),
            'flash' => fn () => $this->getFlashData($request),
            'sidebarOpen' => fn () => $this->getSidebarState($request),
            
            // Global status data for all pages
            'sessionActive' => fn () => $this->getSessionActiveStatus($request),
            'authenticationStatusText' => fn () => $this->getAuthenticationStatusText($request),
            'apiCallStatus' => fn () => $this->getApiCallStatus($request),
            'tunnelStatus' => fn () => $this->getTunnelStatus($request),
            
            // App metadata (cached)
            'app' => Cache::remember('app.metadata', 3600, fn () => [
                'name' => config('app.name'),
                'locale' => config('app.locale'),
                'timezone' => config('app.timezone'),
            ]),
            
            // Performance-related props
            'performance' => [
                'prefetch_enabled' => true,
                'cache_bust' => $this->version($request),
            ],
        ]);
    }
    
    /**
     * Get optimized auth data
     */
    protected function getAuthData(Request $request): ?array
    {
        if (!$request->user()) {
            return null;
        }
        
        // Cache user data for the request lifecycle
        return Cache::remember(
            "user.{$request->user()->id}.auth_data",
            60, // 1 minute cache
            fn () => [
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'email_verified_at' => $request->user()->email_verified_at,
                ],
            ]
        );
    }
    
    /**
     * Get flash messages
     */
    protected function getFlashData(Request $request): array
    {
        $session = $request->session();
        
        return [
            'success' => $session->get('success'),
            'error' => $session->get('error'),
            'warning' => $session->get('warning'),
            'info' => $session->get('info'),
        ];
    }
    
    /**
     * Get sidebar state
     */
    protected function getSidebarState(Request $request): bool
    {
        // Default sidebar state - could be extended to read from user preferences
        return true;
    }
    
    /**
     * Get session active status
     */
    protected function getSessionActiveStatus(Request $request): bool
    {
        // Check if ANAF session is active
        // This could be enhanced to actually check session status
        return Cache::get('anaf.session.active', false);
    }
    
    /**
     * Get authentication status text
     */
    protected function getAuthenticationStatusText(Request $request): string
    {
        $sessionActive = $this->getSessionActiveStatus($request);
        return $sessionActive ? 'Conectat la ANAF' : 'Deconectat de la ANAF';
    }
    
    /**
     * Get API call status
     */
    protected function getApiCallStatus(Request $request): ?array
    {
        // Get API call statistics
        // This should be replaced with actual API call tracking
        $calls_made = Cache::get('api.calls.made', 0);
        $calls_limit = 100; // Default limit
        $calls_remaining = max(0, $calls_limit - $calls_made);
        
        return [
            'calls_made' => $calls_made,
            'calls_limit' => $calls_limit,
            'calls_remaining' => $calls_remaining,
            'reset_at' => now()->endOfDay()->toISOString(),
        ];
    }
    
    /**
     * Get tunnel status
     */
    protected function getTunnelStatus(Request $request): bool
    {
        // Check if cloudflared tunnel is running
        // This could be enhanced to actually check tunnel status
        return Cache::remember('tunnel.status', 30, function () {
            // Simple check - look for cloudflared process
            $output = [];
            $return_var = 0;
            
            if (PHP_OS_FAMILY === 'Windows') {
                exec('tasklist /FI "IMAGENAME eq cloudflared.exe" /FO CSV 2>nul', $output, $return_var);
                return count($output) > 1; // Header + at least one process
            } else {
                exec('pgrep cloudflared', $output, $return_var);
                return $return_var === 0;
            }
        });
    }
    
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Add performance headers
        $response = parent::handle($request, $next);
        
        if ($request->header('X-Inertia')) {
            $response->headers->set('X-Inertia-Performance', 'optimized');
            
            // Add cache headers for static components
            if ($this->isStaticComponent($request)) {
                $response->headers->set('Cache-Control', 'public, max-age=300');
            }
        }
        
        return $response;
    }
    
    /**
     * Check if the component can be cached
     */
    protected function isStaticComponent(Request $request): bool
    {
        $staticComponents = [
            'welcome',
            'auth/login',
            'auth/register',
            'auth/forgot-password',
        ];
        
        $component = $request->header('X-Inertia-Component');
        
        return in_array($component, $staticComponents);
    }
}