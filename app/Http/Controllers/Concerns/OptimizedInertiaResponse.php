<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laravel 12 optimized Inertia responses with selective prop loading
 */
trait OptimizedInertiaResponse
{
    /**
     * Create optimized Inertia response with selective data loading
     */
    protected function inertiaOptimized(string $component, array $props = [], array $options = []): Response
    {
        $request = request();
        
        // Default optimization options
        $defaultOptions = [
            'defer' => [], // Props to defer until client requests them
            'lazy' => [], // Props to load only when component becomes visible
            'merge' => [], // Props to merge with existing data (for infinite scroll)
            'cache' => false, // Whether to cache the response
            'cache_key' => null, // Custom cache key
            'cache_ttl' => 300, // Cache TTL in seconds
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        // Handle deferred props
        if (!empty($options['defer']) && $this->shouldLoadDeferredProps($request, $options['defer'])) {
            $props = $this->loadDeferredProps($props, $options['defer']);
        } else {
            // Remove deferred props from initial load
            foreach ($options['defer'] as $prop) {
                unset($props[$prop]);
            }
        }
        
        // Handle lazy props
        if (!empty($options['lazy']) && $this->shouldLoadLazyProps($request, $options['lazy'])) {
            $props = $this->loadLazyProps($props, $options['lazy']);
        } else {
            // Set placeholders for lazy props
            foreach ($options['lazy'] as $prop) {
                if (!isset($props[$prop])) {
                    $props[$prop] = null; // Placeholder
                }
            }
        }
        
        // Handle merge props for infinite scroll
        if (!empty($options['merge']) && $request->header('X-Inertia-Merge')) {
            $mergeProps = [];
            foreach ($options['merge'] as $prop) {
                if (isset($props[$prop])) {
                    $mergeProps[$prop] = $props[$prop];
                }
            }
            return Inertia::render($component, $mergeProps);
        }
        
        return Inertia::render($component, $props);
    }
    
    /**
     * Check if deferred props should be loaded
     */
    protected function shouldLoadDeferredProps(Request $request, array $deferredProps): bool
    {
        $requestedProps = $request->header('X-Inertia-Partial-Data');
        if (!$requestedProps) {
            return false;
        }
        
        $requestedPropsArray = explode(',', $requestedProps);
        return !empty(array_intersect($deferredProps, $requestedPropsArray));
    }
    
    /**
     * Check if lazy props should be loaded
     */
    protected function shouldLoadLazyProps(Request $request, array $lazyProps): bool
    {
        return $request->header('X-Load-Lazy-Props') === 'true';
    }
    
    /**
     * Load deferred props
     */
    protected function loadDeferredProps(array $props, array $deferredProps): array
    {
        // Override in controller to implement actual deferred loading logic
        return $props;
    }
    
    /**
     * Load lazy props
     */
    protected function loadLazyProps(array $props, array $lazyProps): array
    {
        // Override in controller to implement actual lazy loading logic
        return $props;
    }
    
    /**
     * Create paginated response with optimized data loading
     */
    protected function paginatedResponse(string $component, $paginator, array $additionalProps = []): Response
    {
        $props = [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            ...$additionalProps,
        ];
        
        return $this->inertiaOptimized($component, $props, [
            'merge' => ['data'], // Enable infinite scroll
        ]);
    }
}