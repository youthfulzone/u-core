import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { usePage, router } from '@inertiajs/react';
import { Icon } from '@/components/icon';
import { Wifi, WifiOff, CheckCircle, AlertCircle, Loader2 } from 'lucide-react';
import { useState } from 'react';

interface ApiCallStatus {
    calls_made: number
    calls_limit: number
    calls_remaining: number
    reset_at: string | null
}

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { props } = usePage();
    const sessionActive = props.sessionActive as boolean;
    const authenticationStatusText = props.authenticationStatusText as string;
    const apiCallStatus = props.apiCallStatus as ApiCallStatus | undefined;
    
    const [connectionStatus, setConnectionStatus] = useState<'connected' | 'disconnected' | 'checking'>(
        sessionActive ? 'connected' : 'disconnected'
    );
    const [loading, setLoading] = useState(false);

    const handleConnectionCheck = async () => {
        try {
            setLoading(true);
            setConnectionStatus('checking');
            
            // Add minimum 2 second delay for better UX
            const startTime = Date.now();
            
            // Check session status first
            const sessionResponse = await fetch('/api/anaf/session/status');
            const sessionData = await sessionResponse.json();
            
            // Ensure minimum 2 second delay
            const elapsedTime = Date.now() - startTime;
            const remainingDelay = Math.max(0, 2000 - elapsedTime);
            
            if (remainingDelay > 0) {
                await new Promise(resolve => setTimeout(resolve, remainingDelay));
            }
            
            if (sessionData.success && sessionData.session?.active) {
                setConnectionStatus('connected');
                return;
            }
            
            // If no session, open ANAF authentication page
            const authUrl = 'https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60';
            const authWindow = window.open(authUrl, '_blank', 'width=1200,height=800');
            
            if (authWindow) {
                setConnectionStatus('disconnected');
            }
            
        } catch (error) {
            console.error('Connection check failed:', error);
            setConnectionStatus('disconnected');
        } finally {
            setLoading(false);
        }
    };

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            
            {/* ANAF Status, API Counter, and Auth Button */}
            <div className="ml-auto flex items-center gap-3">
                {/* WiFi Status (without dot) */}
                <div className="flex items-center gap-2">
                    <Icon 
                        iconNode={sessionActive ? Wifi : WifiOff} 
                        className={`h-4 w-4 ${sessionActive ? 'text-green-500' : 'text-orange-500'}`} 
                    />
                </div>
                
                {/* API Call Counter */}
                {apiCallStatus && (
                    <div className="flex items-center gap-1">
                        <div className="text-xs text-muted-foreground">
                            <span className="font-medium text-foreground">{apiCallStatus.calls_remaining}</span>/{apiCallStatus.calls_remaining + apiCallStatus.calls_made} API
                        </div>
                    </div>
                )}
                
                {/* Authentication Button */}
                <Button
                    onClick={handleConnectionCheck}
                    disabled={loading || connectionStatus === 'checking'}
                    variant={connectionStatus === 'connected' ? 'outline' : 'default'}
                    size="sm"
                    className={`transition-colors duration-200 w-[100px] h-8 ${
                        connectionStatus === 'connected' 
                            ? 'border-green-200 bg-green-50 hover:bg-green-100 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300'
                            : connectionStatus === 'disconnected'
                            ? 'bg-orange-500 hover:bg-orange-600 text-white border-orange-500'
                            : ''
                    }`}
                >
                    <div className="flex items-center justify-center gap-1 w-full h-full">
                        <Icon 
                            iconNode={connectionStatus === 'checking' ? Loader2 : connectionStatus === 'connected' ? CheckCircle : AlertCircle}
                            className={`h-3 w-3 ${connectionStatus === 'checking' ? 'animate-spin' : ''} flex-shrink-0`}
                        />
                        <span className="text-xs font-medium truncate">
                            {connectionStatus === 'checking' ? 'Verifică...' : connectionStatus === 'connected' ? 'Verifică' : 'Conectează'}
                        </span>
                    </div>
                </Button>
            </div>
        </header>
    );
}
