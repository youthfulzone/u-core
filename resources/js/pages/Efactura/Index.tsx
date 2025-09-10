import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Head } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { CheckCircle, XCircle, Play, Wifi, WifiOff } from 'lucide-react';

interface EfacturaIndexProps {
    hasCredentials: boolean;
    hasValidToken: boolean;
    tokenExpiresAt?: string;
}

export default function Index({ 
    hasCredentials, 
    hasValidToken, 
    tokenExpiresAt
}: EfacturaIndexProps) {
    const [status, setStatus] = useState({
        hasCredentials,
        hasValidToken,
        tokenExpiresAt,
        tunnelRunning: null as boolean | null
    });
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        // Load real status after page renders
        const loadStatus = async () => {
            try {
                const response = await fetch('/efactura/status');
                const data = await response.json();
                setStatus({
                    hasCredentials: data.hasCredentials,
                    hasValidToken: data.hasValidToken,
                    tokenExpiresAt: data.tokenExpiresAt,
                    tunnelRunning: data.cloudflaredStatus.running
                });
            } catch (error) {
                console.error('Failed to load status:', error);
            }
        };
        loadStatus();
    }, []);

    const handleAuthenticate = async () => {
        setLoading(true);
        try {
            const response = await fetch('/efactura/authenticate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                }
            });
            
            const data = await response.json();
            if (data.auth_url) {
                window.open(data.auth_url, '_blank');
                
                // Poll for token completion
                const pollInterval = setInterval(async () => {
                    const statusResponse = await fetch('/efactura/status');
                    const statusData = await statusResponse.json();
                    if (statusData.hasValidToken) {
                        setStatus(prev => ({ ...prev, hasValidToken: true, tokenExpiresAt: statusData.tokenExpiresAt }));
                        clearInterval(pollInterval);
                        setLoading(false);
                    }
                }, 2000);
                
                setTimeout(() => {
                    clearInterval(pollInterval);
                    setLoading(false);
                }, 300000);
            } else {
                setLoading(false);
            }
        } catch (error) {
            console.error('Authentication failed:', error);
            setLoading(false);
        }
    };

    const handleRevoke = async () => {
        if (!confirm('Revoke access token?')) return;
        
        setLoading(true);
        try {
            await fetch('/efactura/revoke', { 
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                }
            });
            setStatus(prev => ({ ...prev, hasValidToken: false, tokenExpiresAt: undefined }));
        } catch (error) {
            console.error('Failed to revoke token:', error);
        }
        setLoading(false);
    };

    return (
        <AppLayout>
            <Head title="e-Facturi" />
            
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">e-Facturi</h1>
                        <p className="text-sm text-muted-foreground">ANAF electronic invoices</p>
                    </div>
                    
                    {/* Minimal status indicators */}
                    <div className="flex items-center gap-3">
                        <div className="flex items-center gap-1">
                            {status.tunnelRunning === null ? (
                                <div className="h-2 w-2 bg-gray-400 rounded-full animate-pulse" />
                            ) : status.tunnelRunning ? (
                                <Wifi className="h-4 w-4 text-green-600" />
                            ) : (
                                <WifiOff className="h-4 w-4 text-red-600" />
                            )}
                            <span className="text-xs text-muted-foreground">Tunnel</span>
                        </div>
                        
                        <div className="flex items-center gap-1">
                            {status.hasValidToken ? (
                                <CheckCircle className="h-4 w-4 text-green-600" />
                            ) : (
                                <XCircle className="h-4 w-4 text-red-600" />
                            )}
                            <span className="text-xs text-muted-foreground">Auth</span>
                        </div>
                    </div>
                </div>

                {/* Main action */}
                <div className="flex-1 flex items-center justify-center">
                    <div className="text-center space-y-4 max-w-sm">
                        {!status.hasCredentials ? (
                            <div>
                                <Badge variant="destructive" className="mb-3">No Credentials</Badge>
                                <p className="text-sm text-muted-foreground">ANAF credentials not configured</p>
                            </div>
                        ) : !status.hasValidToken ? (
                            <div className="space-y-4">
                                <Badge variant="secondary">Ready to authenticate</Badge>
                                <Button 
                                    onClick={handleAuthenticate}
                                    disabled={loading}
                                    size="lg"
                                    className="w-full gap-2"
                                >
                                    <Play className="h-4 w-4" />
                                    {loading ? 'Authenticating...' : 'Authenticate with ANAF'}
                                </Button>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <Badge variant="default">Authenticated</Badge>
                                {status.tokenExpiresAt && (
                                    <p className="text-xs text-muted-foreground">
                                        Expires: {new Date(status.tokenExpiresAt).toLocaleDateString()}
                                    </p>
                                )}
                                <div className="space-y-2">
                                    <Button 
                                        onClick={handleRevoke}
                                        disabled={loading}
                                        variant="outline"
                                        size="sm"
                                    >
                                        Revoke Token
                                    </Button>
                                    <div className="text-center p-6 border rounded-lg bg-muted/50">
                                        <p className="text-sm text-muted-foreground">
                                            Invoice management features coming soon
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}