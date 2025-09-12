import { Button } from '@/components/ui/button';
import { Icon } from '@/components/icon';
import { Wifi, WifiOff, CheckCircle, AlertCircle, Loader2 } from 'lucide-react';
import { useState, useCallback, memo } from 'react';
import { usePageOptimized } from '@/hooks/use-page-optimized';

interface ApiCallStatus {
    calls_made: number;
    calls_limit: number;
    calls_remaining: number;
    reset_at: string | null;
}

interface GlobalStatusBarProps {
    className?: string;
    compact?: boolean;
}

const GlobalStatusBar = memo(function GlobalStatusBar({ className = '', compact = false }: GlobalStatusBarProps) {
    const { props } = usePageOptimized();
    
    // Extract status data with safe defaults
    const sessionActive = (props.sessionActive as boolean) || false;
    const authenticationStatusText = (props.authenticationStatusText as string) || '';
    const apiCallStatus = props.apiCallStatus as ApiCallStatus | undefined;
    const tunnelStatus = (props.tunnelStatus as boolean) || false;
    
    const [connectionStatus, setConnectionStatus] = useState<'connected' | 'disconnected' | 'checking'>(
        sessionActive ? 'connected' : 'disconnected'
    );
    const [loading, setLoading] = useState(false);

    const handleConnectionCheck = useCallback(async () => {
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
            
            // If no session, open SPV authentication page
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
    }, []);

    if (compact) {
        return (
            <div className={`flex items-center gap-2 ${className}`}>
                {/* Compact Tunnel Status */}
                <Icon 
                    iconNode={tunnelStatus ? Wifi : WifiOff} 
                    className={`h-4 w-4 ${tunnelStatus ? 'text-green-500' : 'text-orange-500'}`} 
                    title={tunnelStatus ? 'Tunnel: Connected' : 'Tunnel: Disconnected'}
                />
                
                {/* Compact SPV Status */}
                <Icon 
                    iconNode={sessionActive ? Wifi : WifiOff} 
                    className={`h-4 w-4 ${sessionActive ? 'text-green-500' : 'text-orange-500'}`}
                    title={sessionActive ? 'SPV: Connected' : 'SPV: Disconnected'}
                />
                
                {/* Compact API Counter */}
                {apiCallStatus ? (
                    <span className="text-xs font-medium text-muted-foreground" title={`API Calls: ${apiCallStatus.calls_remaining} remaining of ${apiCallStatus.calls_limit}`}>
                        {apiCallStatus.calls_remaining}
                    </span>
                ) : (
                    <span className="text-xs font-medium text-muted-foreground" title="API Status: Not available">
                        --
                    </span>
                )}
                
                {/* Compact Connect Button */}
                <Button
                    onClick={handleConnectionCheck}
                    disabled={loading || connectionStatus === 'checking'}
                    variant="outline"
                    size="sm"
                    className="h-6 px-2 text-xs"
                >
                    {connectionStatus === 'checking' ? (
                        <Loader2 className="h-3 w-3 animate-spin" />
                    ) : connectionStatus === 'connected' ? (
                        'Conectat'
                    ) : (
                        'Conectează'
                    )}
                </Button>
            </div>
        );
    }

    return (
        <div className={`flex items-center gap-3 ${className}`}>
            {/* Tunnel Status */}
            <div className="flex items-center gap-1">
                <Icon 
                    iconNode={tunnelStatus ? Wifi : WifiOff} 
                    className={`h-4 w-4 ${tunnelStatus ? 'text-green-500' : 'text-orange-500'}`} 
                />
                <div className="text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">Tunnel</span>
                </div>
            </div>
            
            {/* SPV WiFi Status */}
            <div className="flex items-center gap-1">
                <Icon 
                    iconNode={sessionActive ? Wifi : WifiOff} 
                    className={`h-4 w-4 ${sessionActive ? 'text-green-500' : 'text-orange-500'}`} 
                />
                <div className="text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">SPV</span>
                </div>
            </div>
            
            {/* API Call Counter */}
            {apiCallStatus ? (
                <div className="flex items-center gap-1">
                    <div className="text-xs text-muted-foreground">
                        <span className="font-medium text-foreground">{apiCallStatus.calls_remaining}</span>/{apiCallStatus.calls_limit} API
                    </div>
                </div>
            ) : (
                <div className="flex items-center gap-1">
                    <div className="text-xs text-muted-foreground">
                        <span className="font-medium text-foreground">--</span>/-- API
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
    );
});

export default GlobalStatusBar;