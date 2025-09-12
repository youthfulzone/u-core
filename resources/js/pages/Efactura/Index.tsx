import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Head } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { CheckCircle, XCircle, RefreshCw, Play, Download, Eye, RotateCcw, FileText } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { Icon } from '@/components/icon';

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

interface Invoice {
    _id: string;
    cui?: string;
    download_id: string;
    message_type: string;
    invoice_number: string;
    invoice_date?: string;
    supplier_name: string;
    customer_name: string;
    total_amount: number;
    currency: string;
    status: string;
    download_status: string;
    created_at?: string;
    has_pdf: boolean;
    has_errors: boolean;
}

interface EfacturaIndexProps {
    hasCredentials: boolean;
    tokenStatus: TokenStatus;
    securityDashboard: SecurityDashboard;
    tunnelRunning: boolean;
    invoices: Invoice[];
}

export default function Index({ 
    hasCredentials, 
    tokenStatus,
    securityDashboard,
    tunnelRunning,
    invoices
}: EfacturaIndexProps) {
    const [status, setStatus] = useState({
        hasCredentials,
        hasValidToken: tokenStatus.has_token,
        tokenExpiresAt: tokenStatus.expires_at,
        tunnelRunning
    });
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [syncResults, setSyncResults] = useState<any>(null);
    const [downloadingPdf, setDownloadingPdf] = useState<string | null>(null);

    // No automatic status polling - status comes from server-side render
    
    const breadcrumbs: BreadcrumbItem[] = [
        { href: '/dashboard', title: 'Dashboard' },
        { href: '/efactura', title: 'e-Facturi', active: true }
    ];

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
                
                // Poll for token completion (less frequent)
                const pollInterval = setInterval(async () => {
                    const statusResponse = await fetch('/efactura/status');
                    const statusData = await statusResponse.json();
                    if (statusData.tokenStatus && statusData.tokenStatus.has_token) {
                        setStatus(prev => ({ ...prev, hasValidToken: true, tokenExpiresAt: statusData.tokenStatus.expires_at }));
                        clearInterval(pollInterval);
                        setLoading(false);
                        // Reload the page to get updated token status
                        window.location.reload();
                    }
                }, 5000); // Reduced from 2s to 5s
                
                // Stop polling after 2 minutes instead of 5
                setTimeout(() => {
                    clearInterval(pollInterval);
                    setLoading(false);
                }, 120000);
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

    const handleRefreshToken = async () => {
        setLoading(true);
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
                window.location.reload();
            } else {
                alert(data.error || 'Failed to refresh token');
            }
        } catch (error) {
            console.error('Failed to refresh token:', error);
            alert('Failed to refresh token');
        } finally {
            setLoading(false);
        }
    };

    const handleSyncMessages = async () => {
        setSyncing(true);
        setSyncResults(null);
        try {
            const response = await fetch('/efactura/sync-messages', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                },
                body: JSON.stringify({ days: 30 })
            });
            
            const data = await response.json();
            if (data.success) {
                setSyncResults(data.results);
                // Show results for 5 seconds then reload
                setTimeout(() => {
                    window.location.reload();
                }, 5000);
            } else {
                alert(data.error || 'Failed to sync messages');
            }
        } catch (error) {
            console.error('Failed to sync messages:', error);
            alert('Failed to sync messages');
        } finally {
            setSyncing(false);
        }
    };

    const handleDownloadPDF = async (invoiceId: string) => {
        setDownloadingPdf(invoiceId);
        try {
            const response = await fetch('/efactura/download-pdf', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                },
                body: JSON.stringify({ invoice_id: invoiceId })
            });
            
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `factura_${invoiceId}.pdf`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            } else {
                const error = await response.json();
                alert(error.error || 'Failed to download PDF');
            }
        } catch (error) {
            console.error('Failed to download PDF:', error);
            alert('Failed to download PDF');
        } finally {
            setDownloadingPdf(null);
        }
    };

    const handleViewXML = async (invoiceId: string) => {
        try {
            const response = await fetch('/efactura/view-xml', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                },
                body: JSON.stringify({ invoice_id: invoiceId })
            });
            
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                window.open(url, '_blank');
                window.URL.revokeObjectURL(url);
            } else {
                const error = await response.json();
                alert(error.error || 'Failed to view XML');
            }
        } catch (error) {
            console.error('Failed to view XML:', error);
            alert('Failed to view XML');
        }
    };

    const getMessageTypeBadge = (messageType: string) => {
        switch (messageType) {
            case 'FACTURA TRIMISA':
                return 'default';
            case 'FACTURA PRIMITA':
                return 'secondary';
            case 'ERORI FACTURA':
                return 'destructive';
            default:
                return 'outline';
        }
    };

    const formatDate = (dateString?: string) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        const day = date.getDate().toString().padStart(2, '0');
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const year = date.getFullYear();
        return `${day}.${month}.${year}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="e-Facturi" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
                <div className="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/10 dark:stroke-neutral-100/10 opacity-50" />
                    <div className="relative p-4">
                        {!status.hasCredentials ? (
                            <div className="text-center">
                                <Badge variant="destructive" className="mb-2">Fără credențiale</Badge>
                                <p className="text-sm text-muted-foreground">Credențialele ANAF nu sunt configurate</p>
                            </div>
                        ) : !status.hasValidToken ? (
                            <div className="text-center">
                                <Badge variant="secondary" className="mb-4">Gata pentru autentificare</Badge>
                                <Button 
                                    onClick={handleAuthenticate}
                                    disabled={loading}
                                    size="sm"
                                    className="gap-2"
                                >
                                    <Play className="h-4 w-4" />
                                    {loading ? 'Se autentifică...' : 'Autentificare ANAF'}
                                </Button>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-4">
                                    <Badge 
                                        className="border-green-200 bg-green-50 hover:bg-green-100 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300 flex items-center gap-1"
                                    >
                                        <CheckCircle className="h-3 w-3" />
                                        Activ
                                    </Badge>
                                    <span className="text-sm font-medium">
                                        Expiră în: <span className="font-semibold">{Math.floor(tokenStatus.days_until_expiry || 0)}</span> zile / {formatDate(tokenStatus.expires_at)}
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        onClick={handleSyncMessages}
                                        disabled={syncing}
                                        variant="default"
                                        size="sm"
                                        className="gap-1"
                                    >
                                        <RotateCcw className={`h-3 w-3 ${syncing ? 'animate-spin' : ''}`} />
                                        {syncing ? 'Sincronizează...' : 'Sincronizare facturi'}
                                    </Button>
                                    <Button
                                        onClick={handleRefreshToken}
                                        disabled={!tokenStatus.can_refresh || loading}
                                        variant="outline"
                                        size="sm"
                                        className="gap-1"
                                    >
                                        <RefreshCw className={`h-3 w-3 ${loading ? 'animate-spin' : ''}`} />
                                        {loading ? 'Se reîmprospătează...' : 'Reîmprospătează'}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
                {/* Sync Results Display */}
                {syncResults && (
                    <div className="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border bg-muted/50 p-4">
                        <h3 className="font-semibold mb-3">Rezultate sincronizare</h3>
                        <div className="space-y-2">
                            <p className="text-sm">
                                Total CUI-uri procesate: <span className="font-semibold">{syncResults.total_cuis}</span>
                            </p>
                            <p className="text-sm">
                                Total facturi sincronizate: <span className="font-semibold text-green-600">{syncResults.total_synced}</span>
                            </p>
                            {syncResults.total_errors > 0 && (
                                <p className="text-sm">
                                    Total erori: <span className="font-semibold text-red-600">{syncResults.total_errors}</span>
                                </p>
                            )}
                            {syncResults.synced_by_cui && syncResults.synced_by_cui.length > 0 && (
                                <div className="mt-3 space-y-1">
                                    <p className="text-sm font-medium">Detalii per CUI:</p>
                                    {syncResults.synced_by_cui.map((cui: any, index: number) => (
                                        <div key={index} className="text-xs pl-4">
                                            <span className="font-mono">{cui.cui}</span> - {cui.company_name}: 
                                            {cui.error ? (
                                                <span className="text-red-600 ml-2">{cui.error}</span>
                                            ) : (
                                                <span className="ml-2">
                                                    {cui.synced} din {cui.messages_found} facturi
                                                </span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}
                
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    {/* Invoices table */}
                    <div className="p-6">
                        <div className="flex items-center justify-between mb-4">
                            <h2 className="text-lg font-semibold">Facturi electronice ({invoices.length})</h2>
                        </div>
                        
                        {invoices.length === 0 ? (
                            <div className="text-center py-12">
                                <FileText className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                                <p className="text-muted-foreground font-medium">Nu sunt facturi disponibile</p>
                                <p className="text-sm text-muted-foreground mt-2">
                                    Folosiți butonul "Sincronizare facturi" pentru a prelua facturile de la ANAF
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full table-fixed">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="text-left p-4 font-semibold text-sm w-[80px]">CUI</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[100px]">Tip</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[120px]">Nr. factură</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[90px]">Data</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[160px]">Furnizor</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[160px]">Client</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[90px]">Valoare</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[100px]">Status</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[120px]">Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody className="transition-opacity duration-200 ease-in-out opacity-100">
                                        {invoices.map((invoice, index) => (
                                            <tr 
                                                key={invoice._id} 
                                                className={`border-b hover:bg-muted/30 transition-colors duration-200 ease-in-out ${index % 2 === 0 ? 'bg-background' : 'bg-muted/10'}`}
                                            >
                                                <td className="p-4 align-top w-[80px]">
                                                    <div className="text-xs font-mono">
                                                        {invoice.cui || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[100px]">
                                                    <Badge variant={getMessageTypeBadge(invoice.message_type)} className="text-xs">
                                                        {invoice.message_type === 'FACTURA TRIMISA' ? 'Trimisă' :
                                                         invoice.message_type === 'FACTURA PRIMITA' ? 'Primită' :
                                                         invoice.message_type === 'ERORI FACTURA' ? 'Erori' : invoice.message_type}
                                                    </Badge>
                                                </td>
                                                <td className="p-4 align-top w-[120px]">
                                                    <div className="text-sm font-semibold">
                                                        {invoice.invoice_number || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[90px]">
                                                    <div className="text-sm">
                                                        {invoice.invoice_date || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[160px]">
                                                    <div className="text-sm truncate" title={invoice.supplier_name}>
                                                        {invoice.supplier_name || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[160px]">
                                                    <div className="text-sm truncate" title={invoice.customer_name}>
                                                        {invoice.customer_name || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[90px]">
                                                    <div className="text-sm font-medium">
                                                        {invoice.total_amount > 0 ? 
                                                            `${invoice.total_amount.toFixed(2)} ${invoice.currency}` : 
                                                            'N/A'
                                                        }
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[100px]">
                                                    <div className="flex items-center gap-1">
                                                        <div className="text-xs">
                                                            {invoice.download_status === 'downloaded' && (
                                                                <Badge variant="secondary" className="text-xs">Descărcat</Badge>
                                                            )}
                                                            {invoice.has_errors && (
                                                                <Badge variant="destructive" className="text-xs">Erori</Badge>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[120px]">
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            onClick={() => handleDownloadPDF(invoice._id)}
                                                            disabled={downloadingPdf === invoice._id}
                                                            size="sm"
                                                            variant="outline"
                                                            className="text-xs px-2 h-7"
                                                        >
                                                            {downloadingPdf === invoice._id ? (
                                                                <RefreshCw className="w-3 h-3 animate-spin" />
                                                            ) : (
                                                                <Download className="w-3 h-3" />
                                                            )}
                                                        </Button>
                                                        <Button
                                                            onClick={() => handleViewXML(invoice._id)}
                                                            size="sm"
                                                            variant="outline"
                                                            className="text-xs px-2 h-7"
                                                        >
                                                            <Eye className="w-3 h-3" />
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}