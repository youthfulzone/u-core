import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Head } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { CheckCircle, XCircle, RefreshCw, Play, Download, Eye, RotateCcw, FileText, Trash2, X, AlertCircle, Info, FileDown } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';

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
    total_amount: number | string;
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
    tunnelRunning,
    invoices: initialInvoices
}: EfacturaIndexProps) {
    const [status, setStatus] = useState({
        hasCredentials,
        hasValidToken: tokenStatus.has_token,
        tokenExpiresAt: tokenStatus.expires_at,
        tunnelRunning
    });
    const [invoices, setInvoices] = useState<Invoice[]>(initialInvoices || []);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [downloadingPdf, setDownloadingPdf] = useState<string | null>(null);
    const [generatingPdf, setGeneratingPdf] = useState<string | null>(null);
    const [tunnelStatus, setTunnelStatus] = useState<any>(null);
    const [tunnelLoading, setTunnelLoading] = useState(false);
    const [tunnelManualOperation, setTunnelManualOperation] = useState(false);
    const [clearingDatabase, setClearingDatabase] = useState(false);
    const [syncStatus, setSyncStatus] = useState<any>(null);
    const [autoSyncEnabled, setAutoSyncEnabled] = useState(false);
    const [notifications, setNotifications] = useState<Array<{id: string, type: 'success' | 'error' | 'info', message: string}>>([]);

    // Auto-update tunnel status every 10 seconds
    useEffect(() => {
        const updateTunnelStatus = async () => {
            // Skip automatic updates during manual operations to prevent conflicts
            if (tunnelManualOperation) {
                console.log('Skipping automatic tunnel status update - manual operation in progress');
                return;
            }
            
            try {
                const response = await fetch('/efactura/tunnel-status', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const data = await response.json();
                if (data.success) {
                    setTunnelStatus(data.status);
                }
            } catch (error) {
                console.error('Failed to update tunnel status:', error);
            }
        };

        // Update immediately
        updateTunnelStatus();
        
        // Then update every 10 seconds
        const interval = setInterval(updateTunnelStatus, 10000);
        return () => clearInterval(interval);
    }, [tunnelManualOperation]);
    
    const breadcrumbs: BreadcrumbItem[] = [
        { href: '/dashboard', title: 'Dashboard' },
        { href: '/efactura', title: 'e-Facturi' }
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
                addNotification('success', 'Token reîmprospătat cu succes');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                addNotification('error', data.error || 'Eroare la reîmprospătarea token-ului');
            }
        } catch (error) {
            console.error('Failed to refresh token:', error);
            addNotification('error', 'Eroare la reîmprospătarea token-ului');
        } finally {
            setLoading(false);
        }
    };

    const handleSyncMessages = async () => {
        setSyncing(true);
        try {
            const response = await fetch('/efactura/sync-messages', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                },
                body: JSON.stringify({
                    days: 30
                })
            });

            const data = await response.json();

            if (data.success) {
                console.log('🚀 Sync request successful:', {
                    job_dispatched: data.job_dispatched,
                    sync_id: data.sync_id || 'No sync_id returned'
                });

                // Set syncing immediately for UI feedback
                setSyncing(true);

                if (data.job_dispatched) {
                    addNotification('info', data.message || 'Sincronizare pornită în background');

                    // Force immediate status check after brief delay
                    setTimeout(async () => {
                        try {
                            const checkResponse = await fetch('/efactura/sync-status');
                            const checkData = await checkResponse.json();
                            console.log('🔍 Immediate status check:', checkData);
                            setSyncStatus(checkData);
                        } catch (error) {
                            console.error('Failed immediate status check:', error);
                        }
                    }, 1000);
                } else {
                    addNotification('success', 'Sincronizare finalizată cu succes');
                    setTimeout(() => window.location.reload(), 2000);
                }
            } else {
                addNotification('error', data.error || 'Eroare la sincronizare');
            }
        } catch (error) {
            console.error('Failed to sync messages:', error);
            addNotification('error', 'Eroare la sincronizare');
        }
    };

    const handleGeneratePDF = async (invoiceId: string) => {
        setGeneratingPdf(invoiceId);
        try {
            const response = await fetch('/efactura/generate-pdf', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                },
                body: JSON.stringify({ invoice_id: invoiceId })
            });

            const data = await response.json();
            if (data.success) {
                addNotification('success', 'PDF generat cu succes');
                // Update the invoice in the list to show it has PDF
                setInvoices(prev => prev.map(inv =>
                    inv._id === invoiceId ? { ...inv, has_pdf: true } : inv
                ));
            } else {
                addNotification('error', data.error || 'Eroare la generarea PDF-ului');
            }
        } catch (error) {
            console.error('Failed to generate PDF:', error);
            addNotification('error', 'Eroare la generarea PDF-ului');
        } finally {
            setGeneratingPdf(null);
        }
    };

    const handleDownloadPDF = async (invoiceId: string, hasPdf: boolean) => {
        // Generate PDF first if it doesn't exist
        if (!hasPdf) {
            await handleGeneratePDF(invoiceId);
            // Wait a bit for PDF to be saved
            await new Promise(resolve => setTimeout(resolve, 500));
        }

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
                addNotification('error', error.error || 'Eroare la descărcarea PDF-ului');
            }
        } catch (error) {
            console.error('Failed to download PDF:', error);
            addNotification('error', 'Eroare la descărcarea PDF-ului');
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
                addNotification('error', error.error || 'Eroare la vizualizarea XML-ului');
            }
        } catch (error) {
            console.error('Failed to view XML:', error);
            addNotification('error', 'Eroare la vizualizarea XML-ului');
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

    const handleTunnelControl = async (action: 'start' | 'stop') => {
        console.log(`Tunnel ${action} request started`);
        setTunnelLoading(true);
        setTunnelManualOperation(true); // Block automatic polling
        
        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
            console.log('CSRF Token:', csrfToken ? 'Present' : 'Missing');
            
            const response = await fetch('/efactura/tunnel-control', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action })
            });
            
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            
            const data = await response.json();
            console.log('Response data:', data);
            if (data.success) {
                // Immediately update status from response
                setTunnelStatus(data.status);
                
                // Show success notification
                addNotification('success', `Tunnel ${action === 'start' ? 'pornit' : 'oprit'} cu succes`);
                
                // Check status again after 5 seconds to ensure it's accurate, then allow automatic polling
                setTimeout(async () => {
                    try {
                        const statusResponse = await fetch('/efactura/tunnel-status', {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const statusData = await statusResponse.json();
                        if (statusData.success) {
                            setTunnelStatus(statusData.status);
                        }
                    } catch (error) {
                        console.error('Failed to update tunnel status:', error);
                    } finally {
                        // Re-enable automatic polling after 15 seconds total cooldown
                        setTimeout(() => {
                            setTunnelManualOperation(false);
                        }, 10000); // Additional 10 seconds = 15 total
                    }
                }, 5000); // Check after 5 seconds
            } else {
                addNotification('error', data.message || `Eroare la ${action === 'start' ? 'pornirea' : 'oprirea'} tunnel-ului`);
                setTunnelManualOperation(false); // Re-enable on error
            }
        } catch (error) {
            console.error(`Failed to ${action} tunnel:`, error);
            addNotification('error', `Eroare la ${action === 'start' ? 'pornirea' : 'oprirea'} tunnel-ului`);
            setTunnelManualOperation(false); // Re-enable on error
        } finally {
            setTunnelLoading(false);
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

    const addNotification = (type: 'success' | 'error' | 'info', message: string) => {
        const id = Math.random().toString(36).substr(2, 9);
        setNotifications(prev => [...prev, { id, type, message }]);
        // Auto remove after 5 seconds
        setTimeout(() => {
            setNotifications(prev => prev.filter(n => n.id !== id));
        }, 5000);
    };

    const removeNotification = (id: string) => {
        setNotifications(prev => prev.filter(n => n.id !== id));
    };

    const handleClearDatabase = async () => {
        // Add a confirmation step through React state instead of browser confirm
        const userConfirmed = window.confirm('Sigur doriți să ștergeți toate facturile din baza de date?');
        if (!userConfirmed) {
            addNotification('info', 'Operațiune anulată');
            return;
        }
        
        setClearingDatabase(true);
        try {
            const response = await fetch('/efactura/clear-database', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                }
            });
            
            const data = await response.json();
            if (data.success) {
                addNotification('success', `Baza de date golită cu succes. ${data.deleted_count} facturi șterse.`);
                setTimeout(() => window.location.reload(), 1000);
            } else {
                addNotification('error', data.error || 'Eroare la ștergerea bazei de date');
            }
        } catch (error) {
            console.error('Failed to clear database:', error);
            addNotification('error', 'Eroare la ștergerea bazei de date');
        } finally {
            setClearingDatabase(false);
        }
    };

    // Move fetchRecentInvoices to component level for accessibility
    const fetchRecentInvoices = async (since?: string) => {
        try {
            const params = new URLSearchParams();
            if (since) {
                params.append('since', since);
            }
            params.append('limit', '50');

            // Fetch recent invoices

            const response = await fetch(`/efactura/recent-invoices?${params}`);
            const data = await response.json();

            // Process response

            if (data.invoices && data.invoices.length > 0) {
                setInvoices(prev => {
                    if (since) {
                        // Merge new invoices with existing ones
                        const existingIds = new Set(prev.map(inv => inv._id));
                        const newInvoices = data.invoices.filter((inv: Invoice) => !existingIds.has(inv._id));

                        if (newInvoices.length > 0) {
                            console.log(`✅ Added ${newInvoices.length} new invoices`);
                            // Add new invoices at the beginning, limit total to 200
                            return [...newInvoices, ...prev].slice(0, 200);
                        }
                        return prev;
                    } else {
                        // Full refresh - replace entire list
                        console.log(`🔄 Loaded ${data.invoices.length} invoices`);
                        return data.invoices;
                    }
                });
            }
        } catch (error) {
            console.error('Failed to fetch recent invoices:', error);
        }
    };

    // Update sync status with smart polling to prevent UI spam
    useEffect(() => {
        let intervalRef: NodeJS.Timeout | null = null;
        let lastFetchTime = 0;
        let lastTotalProcessed = 0;
        let isActive = true;
        const FETCH_COOLDOWN = 2000; // Minimum 2 seconds between fetches

        const updateSyncStatus = async () => {
            if (!isActive) return;

            try {
                const response = await fetch('/efactura/sync-status');
                const statusData = await response.json();

                // Simple logging
                if (statusData.is_syncing) {
                    console.log(`📊 Downloading: ${statusData.company_name || 'Company'} (${statusData.current_company}/${statusData.total_companies}) - ${statusData.total_processed || 0} invoices processed`);
                    setSyncing(true);
                }

                // Show errors
                if (statusData.last_error) {
                    console.error('❌ Error:', statusData.last_error);
                }

                // Update UI
                setSyncStatus(statusData);

                // Check if we should fetch new invoices
                if (statusData.is_syncing && statusData.total_processed > 0) {
                    const now = Date.now();
                    const timeSinceLastFetch = now - lastFetchTime;
                    const processedDiff = statusData.total_processed - lastTotalProcessed;

                    // Fetch if we've processed new invoices and cooldown has passed
                    if (processedDiff > 0 && timeSinceLastFetch >= FETCH_COOLDOWN) {
                        console.log(`📥 Fetching invoices - ${processedDiff} new processed`);
                        fetchRecentInvoices(new Date(lastFetchTime || now - 30000).toISOString());
                        lastFetchTime = now;
                        lastTotalProcessed = statusData.total_processed;
                    }
                }

                // Handle sync completion
                if (!statusData.is_syncing || statusData.status === 'completed' || statusData.status === 'failed') {
                    console.log('🏁 Sync finished');

                    // Reset syncing state
                    setSyncing(false);

                    // Final invoice fetch on completion - multiple attempts to ensure we get everything
                    if (statusData.status === 'completed') {
                        console.log('📥 Sync completed - fetching final invoices...');

                        // Immediate fetch
                        fetchRecentInvoices();

                        // Second fetch after 1 second to catch any stragglers
                        setTimeout(() => {
                            if (isActive) {
                                console.log('📥 Final fetch attempt...');
                                fetchRecentInvoices();
                            }
                        }, 1000);
                    }

                    // Stop polling after final fetches
                    setTimeout(() => {
                        if (intervalRef) {
                            clearInterval(intervalRef);
                            intervalRef = null;
                        }
                    }, 3000);

                    // Clear status after 10 seconds
                    setTimeout(() => {
                        if (isActive) {
                            setSyncStatus(prev => {
                                if (prev?.status === 'completed' || prev?.status === 'failed') {
                                    return null;
                                }
                                return prev;
                            });
                        }
                    }, 10000);
                }
            } catch (error) {
                console.error('Failed to update sync status:', error);
            }
        };

        // Always start monitoring - don't depend on syncing state
        console.log('📡 Starting continuous sync monitoring');
        updateSyncStatus(); // Initial call
        intervalRef = setInterval(updateSyncStatus, 1000); // Poll every second

        return () => {
            isActive = false;
            if (intervalRef) {
                clearInterval(intervalRef);
            }
        };
    }, []); // Run once on mount

    // Auto-sync every 10 seconds for testing
    useEffect(() => {
        let interval: NodeJS.Timeout;

        if (autoSyncEnabled && status.hasValidToken) {
            // Don't sync immediately, wait for first interval
            // Set up interval for auto-sync
            interval = setInterval(() => {
                if (!syncing) { // Only sync if not already syncing
                    handleSyncMessages();
                }
            }, 10000); // 10 seconds
        }

        return () => {
            if (interval) clearInterval(interval);
        };
    }, [autoSyncEnabled, status.hasValidToken, syncing]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="e-Facturi" />
            
            {/* Notifications */}
            <div className="fixed top-4 right-4 z-50 space-y-2">
                {notifications.map((notification) => (
                    <div
                        key={notification.id}
                        className={`flex items-center gap-2 p-3 rounded-lg shadow-lg max-w-sm ${
                            notification.type === 'success' ? 'bg-green-50 border border-green-200 text-green-800' :
                            notification.type === 'error' ? 'bg-red-50 border border-red-200 text-red-800' :
                            'bg-blue-50 border border-blue-200 text-blue-800'
                        }`}
                    >
                        {notification.type === 'success' && <CheckCircle className="h-4 w-4" />}
                        {notification.type === 'error' && <AlertCircle className="h-4 w-4" />}
                        {notification.type === 'info' && <Info className="h-4 w-4" />}
                        <span className="text-sm flex-1">{notification.message}</span>
                        <Button
                            onClick={() => removeNotification(notification.id)}
                            variant="ghost"
                            size="sm"
                            className="h-auto p-0.5"
                        >
                            <X className="h-3 w-3" />
                        </Button>
                    </div>
                ))}
            </div>
            
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
                <div className="space-y-4">
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
                            <div className="space-y-4">
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
                                        disabled={syncing || autoSyncEnabled}
                                        variant="default"
                                        size="sm"
                                        className="gap-1"
                                    >
                                        <RotateCcw className={`h-3 w-3 ${syncing ? 'animate-spin' : ''}`} />
                                        {syncing ? 'Sincronizează...' : 'Sincronizare facturi'}
                                    </Button>
                                    <Button
                                        onClick={() => setAutoSyncEnabled(!autoSyncEnabled)}
                                        variant={autoSyncEnabled ? "destructive" : "secondary"}
                                        size="sm"
                                        className="gap-1"
                                    >
                                        {autoSyncEnabled ? (
                                            <>Stop Auto-Sync</>
                                        ) : (
                                            <>Auto-Sync 10s</>
                                        )}
                                    </Button>
                                    <Button
                                        onClick={handleClearDatabase}
                                        disabled={clearingDatabase}
                                        variant="destructive"
                                        size="sm"
                                        className="gap-1"
                                    >
                                        <Trash2 className="h-3 w-3" />
                                        {clearingDatabase ? 'Șterge...' : 'Golire DB'}
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
                            
                            {/* Tunnel Status Row with Sync Status */}
                            <div className="border-t pt-3 mt-3">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs text-muted-foreground">Tunnel OAuth:</span>
                                        {tunnelStatus?.running ? (
                                            <Badge 
                                                className="border-green-200 bg-green-50 hover:bg-green-100 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300 flex items-center gap-1 text-xs"
                                            >
                                                <CheckCircle className="w-3 h-3" />
                                                Activ
                                            </Badge>
                                        ) : (
                                            <Badge 
                                                className="border-red-200 bg-red-50 hover:bg-red-100 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300 flex items-center gap-1 text-xs"
                                            >
                                                <XCircle className="w-3 h-3" />
                                                Oprit
                                            </Badge>
                                        )}
                                        {tunnelStatus?.tunnel_url && (
                                            <span className="text-xs text-muted-foreground">
                                                {tunnelStatus.tunnel_url}
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <Button
                                            onClick={() => handleTunnelControl('start')}
                                            disabled={tunnelLoading || tunnelStatus?.running}
                                            size="sm"
                                            variant="outline"
                                            className="text-xs h-6 px-2"
                                        >
                                            {tunnelLoading ? <RefreshCw className="w-3 h-3 animate-spin" /> : 'Start'}
                                        </Button>
                                        <Button
                                            onClick={() => handleTunnelControl('stop')}
                                            disabled={tunnelLoading || !tunnelStatus?.running}
                                            size="sm"
                                            variant="outline"
                                            className="text-xs h-6 px-2"
                                        >
                                            {tunnelLoading ? <RefreshCw className="w-3 h-3 animate-spin" /> : 'Stop'}
                                        </Button>
                                    </div>
                                </div>
                                
                                {/* Single line sync status with smooth updates */}
                                {(syncStatus?.is_syncing || autoSyncEnabled || syncStatus?.status === 'completed' || syncStatus?.status === 'failed') && (
                                    <div className="flex items-center justify-between mt-2 pt-2 border-t text-xs transition-all duration-300">
                                        <div className="flex items-center gap-3">
                                            {syncStatus?.is_syncing && (
                                                <RefreshCw className="h-3 w-3 animate-spin text-blue-500" />
                                            )}
                                            <span className="text-muted-foreground">Sync:</span>

                                            {/* Company Progress */}
                                            {syncStatus?.total_companies > 0 && (
                                                <>
                                                    <span className="font-medium text-blue-600">
                                                        Company {syncStatus?.current_company || 0}/{syncStatus?.total_companies}
                                                    </span>
                                                    <span className="text-muted-foreground">•</span>
                                                </>
                                            )}

                                            {/* Company Name and CUI */}
                                            {syncStatus?.current_company > 0 && (
                                                <>
                                                    <span className="font-mono text-xs bg-gray-100 px-1 rounded">{syncStatus?.cui}</span>
                                                    <span className="font-medium truncate max-w-[120px]" title={syncStatus?.company_name}>
                                                        {syncStatus?.company_name || '-'}
                                                    </span>
                                                    <span className="text-muted-foreground">•</span>
                                                </>
                                            )}

                                            {/* Invoice Progress */}
                                            {syncStatus?.total_invoices_for_company > 0 && (
                                                <span className="font-medium text-green-600">
                                                    Invoice {syncStatus?.current_invoice || 0}/{syncStatus?.total_invoices_for_company}
                                                </span>
                                            )}

                                            {/* Current Invoice ID */}
                                            {syncStatus?.invoice_identifier && (
                                                <>
                                                    <span className="text-muted-foreground">•</span>
                                                    <span className="font-medium text-xs text-gray-600 truncate max-w-[100px]" title={syncStatus?.invoice_identifier}>
                                                        {syncStatus.invoice_identifier}
                                                    </span>
                                                </>
                                            )}

                                            {/* Test Mode Indicator */}
                                            {syncStatus?.test_mode && (
                                                <>
                                                    <span className="text-muted-foreground">•</span>
                                                    <span className="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded">
                                                        TEST (10s delays)
                                                    </span>
                                                </>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {/* Progress indicator based on total_processed */}
                                            {syncStatus?.total_processed > 0 && (
                                                <div className="flex items-center gap-2">
                                                    <div className="text-xs text-green-600 font-medium">
                                                        {syncStatus.total_processed} processed
                                                    </div>
                                                </div>
                                            )}

                                            {/* Company progress indicator */}
                                            {syncStatus?.current_company > 0 && syncStatus?.total_companies > 0 && (
                                                <div className="flex items-center gap-2">
                                                    <div className="w-24 bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                                        <div
                                                            className="bg-blue-600 h-1.5 rounded-full transition-all duration-500"
                                                            style={{ width: `${Math.round((syncStatus.current_company / syncStatus.total_companies) * 100)}%` }}
                                                        />
                                                    </div>
                                                    <span className="font-medium text-xs">
                                                        {Math.round((syncStatus.current_company / syncStatus.total_companies) * 100)}%
                                                    </span>
                                                </div>
                                            )}
                                            <Badge variant={
                                                syncStatus?.status === 'completed' ? 'secondary' :
                                                syncStatus?.status === 'failed' ? 'destructive' :
                                                syncStatus?.status === 'processing' ? 'default' : 'outline'
                                            } className="text-xs transition-all duration-200">
                                                {syncStatus?.status === 'processing' ? 'Procesare' :
                                                 syncStatus?.status === 'completed' ? 'Finalizat' :
                                                 syncStatus?.status === 'failed' ? 'Eșuat' :
                                                 syncStatus?.status === 'starting' ? 'Pornire...' :
                                                 autoSyncEnabled ? 'Auto-Sync 10s' : 'Inactiv'}
                                            </Badge>
                                        </div>
                                    </div>
                                )}

                                {/* Simple Error Display */}
                                {syncStatus?.last_error && (
                                    <div className="mt-2 p-2 bg-red-50 border border-red-200 rounded text-xs text-red-700">
                                        <span className="font-medium">Error:</span> {syncStatus.last_error}
                                    </div>
                                )}

                                {/* Total Errors Counter */}
                                {syncStatus?.total_errors > 0 && (
                                    <div className="mt-1 text-xs text-orange-600">
                                        {syncStatus.total_errors} error(s) encountered during sync
                                    </div>
                                )}
                            </div>
                            </div>
                        )}
                        
                        </div>
                    </div>
                
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
                                        <tr key="header">
                                            <th className="text-left p-4 font-semibold text-sm w-[80px]">CUI</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[100px]">Tip</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[120px]">Nr. factură</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[90px]">Data</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[160px]">Furnizor</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[160px]">Client</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[90px]">Valoare</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[100px]">Status</th>
                                            <th className="text-left p-4 font-semibold text-sm w-[140px]">Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody className="transition-opacity duration-200 ease-in-out opacity-100">
                                        {invoices.map((invoice, index) => (
                                            <tr
                                                key={`invoice-${invoice._id}-${index}`}
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
                                                        {invoice.message_type === 'FACTURA TRIMISA' ? '-' : 
                                                         invoice.supplier_name || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[160px]">
                                                    <div className="text-sm truncate" title={invoice.customer_name}>
                                                        {invoice.message_type === 'FACTURA PRIMITA' ? '-' : 
                                                         invoice.customer_name || 'N/A'}
                                                    </div>
                                                </td>
                                                <td className="p-4 align-top w-[90px]">
                                                    <div className="text-sm font-medium">
                                                        {invoice.total_amount && parseFloat(invoice.total_amount.toString()) > 0 ? 
                                                            `${parseFloat(invoice.total_amount.toString()).toFixed(2)} ${invoice.currency}` : 
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
                                                <td className="p-4 align-top w-[140px]">
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            onClick={() => handleDownloadPDF(invoice._id, invoice.has_pdf)}
                                                            disabled={downloadingPdf === invoice._id || generatingPdf === invoice._id}
                                                            size="sm"
                                                            variant={invoice.has_pdf ? "outline" : "default"}
                                                            className="text-xs px-2 h-7 min-w-[50px]"
                                                            title={invoice.has_pdf ? "Descarcă PDF" : "Generează și descarcă PDF"}
                                                        >
                                                            {downloadingPdf === invoice._id || generatingPdf === invoice._id ? (
                                                                <RefreshCw className="w-3 h-3 animate-spin" />
                                                            ) : (
                                                                <span className="flex items-center gap-1">
                                                                    {invoice.has_pdf ? (
                                                                        <Download className="w-3 h-3" />
                                                                    ) : (
                                                                        <FileDown className="w-3 h-3" />
                                                                    )}
                                                                    <span>PDF</span>
                                                                </span>
                                                            )}
                                                        </Button>
                                                        <Button
                                                            onClick={() => handleViewXML(invoice._id)}
                                                            size="sm"
                                                            variant="outline"
                                                            className="text-xs px-2 h-7 min-w-[50px]"
                                                            title="Vizualizează XML"
                                                        >
                                                            <span className="flex items-center gap-1">
                                                                <Eye className="w-3 h-3" />
                                                                <span>XML</span>
                                                            </span>
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
            </div>
        </AppLayout>
    );
}