import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Separator } from '@/components/ui/separator';
import { RefreshCw, Shield, AlertTriangle, Clock, CheckCircle2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { router } from '@inertiajs/react';

interface TokenStatus {
    has_token: boolean;
    status: 'active' | 'expiring_warning' | 'expiring_soon' | 'no_token';
    token_id?: string;
    issued_at?: string;
    expires_at?: string;
    days_until_expiry?: number;
    days_since_issued?: number;
    can_refresh?: boolean;
    days_until_refresh?: number;
    usage_count?: number;
    last_used_at?: string;
    message: string;
}

interface SecurityDashboard {
    active_tokens: any[];
    expiring_tokens: any[];
    pending_revocations: any[];
    total_tokens_issued: number;
    compromised_count: number;
}

interface TokenStatusDisplayProps {
    tokenStatus: TokenStatus;
    securityDashboard: SecurityDashboard;
}

export default function TokenStatusDisplay({ tokenStatus, securityDashboard }: TokenStatusDisplayProps) {
    const [loading, setLoading] = useState(false);
    const [refreshLoading, setRefreshLoading] = useState(false);

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'active': return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100';
            case 'expiring_warning': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100';
            case 'expiring_soon': return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100';
            case 'no_token': return 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-100';
            default: return 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-100';
        }
    };

    const getStatusIcon = (status: string) => {
        switch (status) {
            case 'active': return <CheckCircle2 className="h-4 w-4" />;
            case 'expiring_warning': return <Clock className="h-4 w-4" />;
            case 'expiring_soon': return <AlertTriangle className="h-4 w-4" />;
            case 'no_token': return <XCircle className="h-4 w-4" />;
            default: return <XCircle className="h-4 w-4" />;
        }
    };

    const handleRefreshToken = async () => {
        setRefreshLoading(true);
        try {
            const response = await fetch('/efactura/refresh-token', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                }
            });
            
            const data = await response.json();
            if (data.success) {
                router.reload({ only: ['tokenStatus', 'securityDashboard'] });
            } else {
                alert(data.error || 'Failed to refresh token');
            }
        } catch (error) {
            console.error('Failed to refresh token:', error);
            alert('Failed to refresh token');
        } finally {
            setRefreshLoading(false);
        }
    };

    const handleMarkCompromised = async () => {
        if (!tokenStatus.token_id) return;
        
        const reason = prompt('Please provide a reason for marking this token as compromised:');
        if (!reason) return;

        setLoading(true);
        try {
            const response = await fetch('/efactura/mark-compromised', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                },
                body: JSON.stringify({
                    token_id: tokenStatus.token_id,
                    reason: reason
                })
            });
            
            const data = await response.json();
            if (data.success) {
                router.reload({ only: ['tokenStatus', 'securityDashboard'] });
                alert('Token marked as compromised. ANAF revocation request has been generated.');
            } else {
                alert(data.error || 'Failed to mark token as compromised');
            }
        } catch (error) {
            console.error('Failed to mark token as compromised:', error);
            alert('Failed to mark token as compromised');
        } finally {
            setLoading(false);
        }
    };

    const formatDate = (dateString?: string) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('ro-RO', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    return (
        <div className="space-y-4">
            {/* Main Token Status Card */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Shield className="h-5 w-5" />
                        Token Status
                    </CardTitle>
                    <CardDescription>
                        Live e-Factura authentication token status with daily updates
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {tokenStatus.has_token ? (
                        <>
                            {/* Status Badge */}
                            <div className="flex items-center gap-2">
                                <Badge className={`${getStatusColor(tokenStatus.status)} flex items-center gap-1`}>
                                    {getStatusIcon(tokenStatus.status)}
                                    {tokenStatus.status.replace('_', ' ').toUpperCase()}
                                </Badge>
                                <span className="text-sm text-muted-foreground">{tokenStatus.message}</span>
                            </div>

                            {/* Status warning for expiring tokens */}
                            {(tokenStatus.status === 'expiring_soon' || tokenStatus.status === 'expiring_warning') && (
                                <Alert>
                                    <AlertTriangle className="h-4 w-4" />
                                    <AlertDescription>
                                        {tokenStatus.status === 'expiring_soon' 
                                            ? `⚠️ Critical: Token expires in ${tokenStatus.days_until_expiry} days! Take action immediately.`
                                            : `Token expires in ${tokenStatus.days_until_expiry} days. Consider refreshing soon.`
                                        }
                                    </AlertDescription>
                                </Alert>
                            )}

                            {/* Token Details Grid */}
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 bg-muted/50 rounded-lg">
                                <div>
                                    <div className="text-sm font-medium text-muted-foreground">Expires</div>
                                    <div className="text-sm">{formatDate(tokenStatus.expires_at)}</div>
                                    <div className="text-xs text-muted-foreground">in {tokenStatus.days_until_expiry} days</div>
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-muted-foreground">Issued</div>
                                    <div className="text-sm">{formatDate(tokenStatus.issued_at)}</div>
                                    <div className="text-xs text-muted-foreground">{tokenStatus.days_since_issued} days ago</div>
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-muted-foreground">Usage</div>
                                    <div className="text-sm">{tokenStatus.usage_count} calls</div>
                                    <div className="text-xs text-muted-foreground">
                                        Last: {tokenStatus.last_used_at ? formatDate(tokenStatus.last_used_at) : 'Never'}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-sm font-medium text-muted-foreground">Refresh</div>
                                    <div className="text-sm">
                                        {tokenStatus.can_refresh ? (
                                            <span className="text-green-600">Available</span>
                                        ) : (
                                            <span className="text-orange-600">In {tokenStatus.days_until_refresh} days</span>
                                        )}
                                    </div>
                                    <div className="text-xs text-muted-foreground">90-day minimum</div>
                                </div>
                            </div>

                            {/* Action Buttons */}
                            <div className="flex gap-2">
                                <Button
                                    onClick={handleRefreshToken}
                                    disabled={!tokenStatus.can_refresh || refreshLoading}
                                    variant="outline"
                                    size="sm"
                                    className="flex items-center gap-1"
                                >
                                    <RefreshCw className={`h-4 w-4 ${refreshLoading ? 'animate-spin' : ''}`} />
                                    {refreshLoading ? 'Refreshing...' : 'Refresh Token'}
                                </Button>
                                <Button
                                    onClick={handleMarkCompromised}
                                    disabled={loading}
                                    variant="destructive"
                                    size="sm"
                                    className="flex items-center gap-1"
                                >
                                    <Shield className="h-4 w-4" />
                                    Mark Compromised
                                </Button>
                            </div>
                        </>
                    ) : (
                        <div className="text-center py-6">
                            <XCircle className="h-12 w-12 text-muted-foreground mx-auto mb-2" />
                            <p className="text-muted-foreground">{tokenStatus.message}</p>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Security Dashboard */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Shield className="h-5 w-5" />
                        Security Dashboard
                    </CardTitle>
                    <CardDescription>
                        Comprehensive overview of token security status
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                        <div className="text-center">
                            <div className="text-2xl font-bold text-green-600">{securityDashboard.active_tokens.length}</div>
                            <div className="text-sm text-muted-foreground">Active Tokens</div>
                        </div>
                        <div className="text-center">
                            <div className="text-2xl font-bold text-yellow-600">{securityDashboard.expiring_tokens.length}</div>
                            <div className="text-sm text-muted-foreground">Expiring Soon</div>
                        </div>
                        <div className="text-center">
                            <div className="text-2xl font-bold text-red-600">{securityDashboard.pending_revocations.length}</div>
                            <div className="text-sm text-muted-foreground">Pending Revocations</div>
                        </div>
                        <div className="text-center">
                            <div className="text-2xl font-bold text-blue-600">{securityDashboard.total_tokens_issued}</div>
                            <div className="text-sm text-muted-foreground">Total Issued</div>
                        </div>
                        <div className="text-center">
                            <div className="text-2xl font-bold text-red-600">{securityDashboard.compromised_count}</div>
                            <div className="text-sm text-muted-foreground">Compromised</div>
                        </div>
                    </div>

                    {securityDashboard.pending_revocations.length > 0 && (
                        <>
                            <Separator className="my-4" />
                            <Alert>
                                <AlertTriangle className="h-4 w-4" />
                                <AlertDescription>
                                    <strong>Action Required:</strong> {securityDashboard.pending_revocations.length} token(s) marked as compromised require manual ANAF revocation.
                                </AlertDescription>
                            </Alert>
                        </>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}