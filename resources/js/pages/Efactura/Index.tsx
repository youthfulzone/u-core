import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { type BreadcrumbItem } from '@/types';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';

// Import our new components
import TokenManagement from './components/TokenManagement';
import TunnelManagement from './components/TunnelManagement';
import Notifications from './components/Notifications';

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


interface Notification {
    id: string;
    type: 'success' | 'error' | 'info';
    message: string;
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
    const [loading, setLoading] = useState(false);
    const [tunnelStatus, setTunnelStatus] = useState<any>(null);
    const [tunnelLoading, setTunnelLoading] = useState(false);
    const [tunnelManualOperation, setTunnelManualOperation] = useState(false);
    const [notifications, setNotifications] = useState<Notification[]>([]);

    const breadcrumbs: BreadcrumbItem[] = [
        { href: '/dashboard', title: 'Dashboard' },
        { href: '/efactura', title: 'e-Facturi' }
    ];

    // Notification management
    const addNotification = (type: 'success' | 'error' | 'info', message: string) => {
        const id = Math.random().toString(36).substr(2, 9);
        setNotifications(prev => [...prev, { id, type, message }]);
        setTimeout(() => {
            setNotifications(prev => prev.filter(n => n.id !== id));
        }, 5000);
    };

    const removeNotification = (id: string) => {
        setNotifications(prev => prev.filter(n => n.id !== id));
    };

    // Token management handlers
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

                const pollInterval = setInterval(async () => {
                    const statusResponse = await fetch('/efactura/status');
                    const statusData = await statusResponse.json();
                    if (statusData.tokenStatus && statusData.tokenStatus.has_token) {
                        setStatus(prev => ({ ...prev, hasValidToken: true, tokenExpiresAt: statusData.tokenStatus.expires_at }));
                        clearInterval(pollInterval);
                        setLoading(false);
                        // Use Inertia reload instead of window reload
                        router.reload();
                    }
                }, 5000);

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
                // Use Inertia reload instead of window reload
                setTimeout(() => router.reload(), 1000);
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



    // Tunnel management handlers
    const handleTunnelControl = async (action: 'start' | 'stop') => {
        console.log(`Tunnel ${action} request started`);
        setTunnelLoading(true);
        setTunnelManualOperation(true);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

            const response = await fetch('/efactura/tunnel-control', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action })
            });

            const data = await response.json();
            if (data.success) {
                setTunnelStatus(data.status);
                addNotification('success', `Tunnel ${action === 'start' ? 'pornit' : 'oprit'} cu succes`);

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
                        setTimeout(() => {
                            setTunnelManualOperation(false);
                        }, 10000);
                    }
                }, 5000);
            } else {
                addNotification('error', data.message || `Eroare la ${action === 'start' ? 'pornirea' : 'oprirea'} tunnel-ului`);
                setTunnelManualOperation(false);
            }
        } catch (error) {
            console.error(`Failed to ${action} tunnel:`, error);
            addNotification('error', `Eroare la ${action === 'start' ? 'pornirea' : 'oprirea'} tunnel-ului`);
            setTunnelManualOperation(false);
        } finally {
            setTunnelLoading(false);
        }
    };

    // Invoice management handlers
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
        if (!hasPdf) {
            await handleGeneratePDF(invoiceId);
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

    // Auto-update tunnel status every 10 seconds
    useEffect(() => {
        const updateTunnelStatus = async () => {
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

        updateTunnelStatus();
        const interval = setInterval(updateTunnelStatus, 10000);
        return () => clearInterval(interval);
    }, [tunnelManualOperation]);

    // No monitoring needed for simple sync - it completes synchronously

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="e-Facturi" />

            <Notifications notifications={notifications} onRemove={removeNotification} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
                <div className="space-y-4">
                    <div className="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/10 dark:stroke-neutral-100/10 opacity-50" />
                        <div className="relative p-4">
                            <TokenManagement
                                hasCredentials={status.hasCredentials}
                                tokenStatus={tokenStatus}
                                onAuthenticate={handleAuthenticate}
                                onRefreshToken={handleRefreshToken}
                                loading={loading}
                            />

                            {status.hasValidToken && (
                                <>

                                    <TunnelManagement
                                        tunnelStatus={tunnelStatus}
                                        tunnelLoading={tunnelLoading}
                                        onTunnelControl={handleTunnelControl}
                                    />
                                </>
                            )}
                        </div>
                    </div>

                </div>
            </div>
        </AppLayout>
    );
}