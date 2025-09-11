import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { type SharedData } from '@/types';

/**
 * Optimized usePage hook that memoizes frequently accessed properties
 * React 19 compatible with automatic batching and concurrent features
 */
export function usePageOptimized<T = SharedData>() {
    const page = usePage<T>();
    
    // Memoize commonly accessed properties to prevent unnecessary re-renders
    const memoizedProps = useMemo(() => ({
        url: page.url,
        props: page.props,
        component: page.component,
    }), [page.url, page.props, page.component]);

    // Memoize auth data separately since it's accessed frequently
    const auth = useMemo(() => {
        const sharedData = page.props as SharedData;
        return sharedData.auth;
    }, [(page.props as SharedData).auth]);

    // Memoize flash messages
    const flash = useMemo(() => {
        const sharedData = page.props as SharedData;
        return sharedData.flash;
    }, [(page.props as SharedData).flash]);

    return {
        ...memoizedProps,
        auth,
        flash,
        // Original page object for compatibility
        page,
    };
}

/**
 * Hook for route matching with memoized results
 */
export function useRouteMatch() {
    const { url } = usePageOptimized();
    
    return useMemo(() => ({
        /**
         * Check if current route matches exactly
         */
        isExact: (route: string) => url === route,
        
        /**
         * Check if current route starts with given path
         */
        startsWith: (route: string) => url.startsWith(route),
        
        /**
         * Check if current route matches any of the patterns
         */
        matches: (routes: string[]) => routes.some(route => 
            url === route || url.startsWith(route + '/')
        ),
        
        /**
         * Get active status for navigation items
         */
        isActive: (href: string, exact: boolean = false) => {
            if (exact) return url === href;
            return url === href || url.startsWith(href + '/');
        }
    }), [url]);
}