import type { route as routeFn } from 'ziggy-js';

declare global {
    const route: typeof routeFn;
    
    interface Window {
        anafCookieHelper?: {
            version: string
            isExtensionActive: boolean
            getCookies(): Promise<{
                success: boolean
                cookies?: Record<string, string>
                timestamp?: string
                error?: string
            }>
            syncCookies(appUrl?: string): Promise<{
                success: boolean
                message?: string
                cookieCount?: number
                error?: string
            }>
            checkAnafAuth(): Promise<{
                authenticated: boolean
                cookieCount?: number
                cookies?: Record<string, string>
                error?: string
            }>
            formatCookiesForLaravel(cookies: Record<string, string>): string
        }
    }
}
