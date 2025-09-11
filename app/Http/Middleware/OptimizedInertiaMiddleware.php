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
        return $request->user()?->getSidebarState() ?? true;
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