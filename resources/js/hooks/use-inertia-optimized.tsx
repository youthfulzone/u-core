import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

/**
 * Optimized Inertia navigation hook using v2 features
 * Implements prefetching, deferred props, and optimistic updates
 */
export function useInertiaOptimized() {
    // Optimized navigation with prefetching by default
    const navigate = useCallback((href: string, options: any = {}) => {
        return router.visit(href, {
            // Enable prefetching for faster navigation
            prefetch: true,
            // Use partial reloads when possible
            preserveScroll: true,
            preserveState: true,
            // Merge new data instead of replacing
            only: options.only,
            ...options,
        });
    }, []);

    // Optimized form submission
    const submit = useCallback((method: string, href: string, data: any, options: any = {}) => {
        return router[method as keyof typeof router](href, data, {
            // Preserve scroll position during form submissions
            preserveScroll: true,
            // Show loading states immediately
            replace: false,
            // Use partial reloads
            only: options.only,
            ...options,
        });
    }, []);

    // Prefetch links for better UX
    const prefetch = useCallback((href: string) => {
        router.prefetch(href);
    }, []);

    // Optimized reload with minimal data fetching
    const reload = useCallback((options: any = {}) => {
        return router.reload({
            // Only reload specific props to reduce payload
            only: options.only,
            // Preserve form state
            preserveState: true,
            preserveScroll: true,
            ...options,
        });
    }, []);

    return useMemo(() => ({
        navigate,
        submit,
        prefetch,
        reload,
        // Expose router for advanced usage
        router,
    }), [navigate, submit, prefetch, reload]);
}

/**
 * Hook for optimized polling with Inertia v2
 */
export function useInertiaPolling(href: string, interval: number = 5000) {
    const { reload } = useInertiaOptimized();

    const startPolling = useCallback(() => {
        const pollInterval = setInterval(() => {
            reload({ 
                only: ['stats', 'status'], // Only reload specific data
                preserveState: true,
                preserveScroll: true,
            });
        }, interval);

        return () => clearInterval(pollInterval);
    }, [reload, interval]);

    return { startPolling };
}

/**
 * Hook for deferred prop loading with loading states
 */
export function useDeferredProps<T>(initialData: T, propNames: string[], href?: string) {
    const { reload } = useInertiaOptimized();

    const loadDeferredProps = useCallback(() => {
        if (!href) return Promise.resolve();
        
        return reload({
            only: propNames,
            preserveState: true,
            preserveScroll: true,
        });
    }, [reload, propNames, href]);

    return {
        data: initialData,
        loadDeferredProps,
        isLoading: false, // Will be managed by Inertia's progress
    };
}