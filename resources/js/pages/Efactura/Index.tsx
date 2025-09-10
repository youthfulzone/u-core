import AuthenticatedLayout from '@/layouts/authenticated-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Head, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { 
    CheckCircle, 
    XCircle, 
    AlertCircle, 
    RefreshCw, 
    ExternalLink,
    Play,
    Terminal,
    Wifi,
    WifiOff
} from 'lucide-react';

interface CloudflaredStatus {
    running: boolean;
    tunnel_url?: string;
    callback_url?: string;
    message: string;
    required: boolean;
    setup_command?: string;
}

interface EfacturaStatus {
    hasCredentials: boolean;
    hasValidToken: boolean;
    tokenExpiresAt?: string;
    cloudflaredStatus: CloudflaredStatus;
}

interface EfacturaIndexProps {
    hasCredentials: boolean;
    hasValidToken: boolean;
    tokenExpiresAt?: string;
    cloudflaredStatus: CloudflaredStatus;
}

export default function Index({ 
    hasCredentials, 
    hasValidToken, 
    tokenExpiresAt,
    cloudflaredStatus: initialCloudflaredStatus 
}: EfacturaIndexProps) {
    const [status, setStatus] = useState<EfacturaStatus>({
        hasCredentials,
        hasValidToken,
        tokenExpiresAt,
        cloudflaredStatus: initialCloudflaredStatus
    });
    const [loading, setLoading] = useState(false);

    const refreshStatus = async () => {
        try {
            const response = await fetch('/efactura/status');
            const data = await response.json();
            setStatus(data);
        } catch (error) {
            console.error('Failed to refresh status:', error);
        }
    };

    const handleAuthenticate = async () => {
        if (!status.cloudflaredStatus.running) {
            alert('Cloudflared tunnel must be running for OAuth to work. Please start the tunnel first.');
            return;
        }

        setLoading(true);
        try {
            const response = await fetch('/efactura/authenticate', { method: 'POST' });
            const data = await response.json();
            
            if (data.auth_url) {
                // Open OAuth URL in new window
                window.open(data.auth_url, '_blank');
                
                // Start polling for status updates
                const pollInterval = setInterval(async () => {
                    await refreshStatus();
                    if (status.hasValidToken) {
                        clearInterval(pollInterval);
                        setLoading(false);
                    }
                }, 2000);
                
                // Stop polling after 5 minutes
                setTimeout(() => {
                    clearInterval(pollInterval);
                    setLoading(false);
                }, 300000);
            }
        } catch (error) {
            console.error('Authentication failed:', error);
            setLoading(false);
        }
    };

    const handleRevoke = async () => {
        if (!confirm('Are you sure you want to revoke the access token?')) return;
        
        setLoading(true);
        try {
            await fetch('/efactura/revoke', { method: 'POST' });
            await refreshStatus();
        } catch (error) {
            console.error('Failed to revoke token:', error);
        }
        setLoading(false);
    };

    const getTokenStatus = () => {
        if (!status.hasCredentials) {
            return { color: 'destructive' as const, text: 'No Credentials', icon: XCircle };
        }
        if (!status.hasValidToken) {
            return { color: 'destructive' as const, text: 'Not Authenticated', icon: XCircle };
        }
        return { color: 'default' as const, text: 'Authenticated', icon: CheckCircle };
    };

    const getCloudflaredStatus = () => {
        if (status.cloudflaredStatus.running) {
            return { color: 'default' as const, text: 'Active', icon: Wifi };
        }
        return { color: 'destructive' as const, text: 'Inactive', icon: WifiOff };
    };

    const tokenStatusInfo = getTokenStatus();
    const cloudflaredStatusInfo = getCloudflaredStatus();

    return (
        <AuthenticatedLayout>
            <Head title="e-Facturi" />
            
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">e-Facturi</h1>
                        <p className="text-muted-foreground">
                            Manage electronic invoices through ANAF integration
                        </p>
                    </div>
                    <Button 
                        onClick={refreshStatus} 
                        variant="outline" 
                        size="sm"
                        className="gap-2"
                    >
                        <RefreshCw className="h-4 w-4" />
                        Refresh Status
                    </Button>
                </div>

                {/* Cloudflared Status Alert */}
                {!status.cloudflaredStatus.running && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription className="space-y-2">
                            <div>
                                <strong>Cloudflared tunnel required:</strong> {status.cloudflaredStatus.message}
                            </div>
                            {status.cloudflaredStatus.setup_command && (
                                <div className="flex items-center gap-2 mt-2">
                                    <Terminal className="h-4 w-4" />
                                    <code className="bg-muted px-2 py-1 rounded text-sm">
                                        {status.cloudflaredStatus.setup_command}
                                    </code>
                                </div>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 md:grid-cols-2">
                    {/* Authentication Status */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <tokenStatusInfo.icon className="h-5 w-5" />
                                Authentication Status
                            </CardTitle>
                            <CardDescription>
                                ANAF OAuth token status and management
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span>Status:</span>
                                <Badge variant={tokenStatusInfo.color}>
                                    {tokenStatusInfo.text}
                                </Badge>
                            </div>
                            
                            {status.hasCredentials && (
                                <div className="flex items-center justify-between">
                                    <span>Credentials:</span>
                                    <Badge variant="default">
                                        <CheckCircle className="h-3 w-3 mr-1" />
                                        Configured
                                    </Badge>
                                </div>
                            )}
                            
                            {status.tokenExpiresAt && (
                                <div className="flex items-center justify-between">
                                    <span>Token expires:</span>
                                    <span className="text-sm text-muted-foreground">
                                        {new Date(status.tokenExpiresAt).toLocaleDateString()}
                                    </span>
                                </div>
                            )}
                            
                            <div className="pt-4 space-y-2">
                                {!status.hasValidToken ? (
                                    <Button 
                                        onClick={handleAuthenticate}
                                        disabled={loading || !status.cloudflaredStatus.running}
                                        className="w-full gap-2"
                                    >
                                        <Play className="h-4 w-4" />
                                        {loading ? 'Authenticating...' : 'Authenticate with ANAF'}
                                    </Button>
                                ) : (
                                    <Button 
                                        onClick={handleRevoke}
                                        disabled={loading}
                                        variant="destructive"
                                        className="w-full gap-2"
                                    >
                                        <XCircle className="h-4 w-4" />
                                        Revoke Access Token
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Cloudflared Status */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <cloudflaredStatusInfo.icon className="h-5 w-5" />
                                Cloudflared Tunnel
                            </CardTitle>
                            <CardDescription>
                                Required for OAuth callback from ANAF
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span>Status:</span>
                                <Badge variant={cloudflaredStatusInfo.color}>
                                    {cloudflaredStatusInfo.text}
                                </Badge>
                            </div>
                            
                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <span>Message:</span>
                                    <span className="text-sm text-muted-foreground text-right max-w-48">
                                        {status.cloudflaredStatus.message}
                                    </span>
                                </div>
                                
                                {status.cloudflaredStatus.tunnel_url && (
                                    <div className="flex items-center justify-between">
                                        <span>Tunnel URL:</span>
                                        <a 
                                            href={status.cloudflaredStatus.tunnel_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-sm text-blue-600 hover:underline flex items-center gap-1"
                                        >
                                            efactura.scyte.ro
                                            <ExternalLink className="h-3 w-3" />
                                        </a>
                                    </div>
                                )}
                                
                                {status.cloudflaredStatus.callback_url && (
                                    <div className="flex items-center justify-between">
                                        <span>Callback URL:</span>
                                        <span className="text-sm text-muted-foreground">
                                            /efactura/oauth/callback
                                        </span>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Placeholder for future invoice management */}
                {status.hasValidToken && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Invoice Management</CardTitle>
                            <CardDescription>
                                Upload and manage electronic invoices (Coming soon)
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="text-center py-8 text-muted-foreground">
                                Invoice upload and management features will be available here
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}