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
            getStatus(): {
                connected: boolean
                lastPing: number
                lastSync: number | null
                lastError: string | null
                connectionHealth: 'unknown' | 'healthy' | 'error'
                authorized: boolean
                domain: string
                uptime: number
                version: string
            }
            testConnection(): Promise<{
                success: boolean
                latency?: number
                message?: string
                extensionHealth?: 'healthy' | 'error'
                backgroundScript?: 'responsive' | 'unresponsive'
                error?: string
            }>
            manualSync(): Promise<{
                success: boolean
                message?: string
                cookieCount?: number
                error?: string
            }>
            getCookieCount(): Promise<number>
            formatCookiesForLaravel(cookies: Record<string, string>): string
        }
    }
}
