import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Head } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { CheckCircle, XCircle, RefreshCw, Download, Eye, FileText } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

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
    has_pdf: boolean;
    has_errors: boolean;
}

interface SimpleIndexProps {
    hasCredentials: boolean;
    tokenStatus: any;
    tunnelRunning: boolean;
    invoices: Invoice[];
}

export default function SimpleIndex({
    hasCredentials,
    tokenStatus,
    tunnelRunning,
    invoices: initialInvoices
}: SimpleIndexProps) {
    const [invoices, setInvoices] = useState<Invoice[]>(initialInvoices || []);
    const [syncing, setSyncing] = useState(false);
    const [syncStatus, setSyncStatus] = useState<any>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { label: 'Dashboard', href: '/dashboard' },
        { label: 'e-Facturi', href: '/efactura' }
    ];

    // Simple sync monitoring
    useEffect(() => {
        let interval: NodeJS.Timeout | null = null;

        const checkStatus = async () => {
            try {
                const response = await fetch('/efactura/sync-status');
                const data = await response.json();

                setSyncStatus(data);

                if (data.status === 'running') {
                    console.log(`📊 ${data.message || 'Syncing...'}`);
                    setSyncing(true);
                } else if (data.status === 'completed') {
                    console.log('✅ Sync completed - refreshing...');
                    setSyncing(false);

                    // Refresh invoices
                    const invoiceResponse = await fetch('/efactura/recent-invoices?limit=50');
                    const invoiceData = await invoiceResponse.json();
                    if (invoiceData.invoices) {
                        setInvoices(invoiceData.invoices);
                        console.log(`🔄 Loaded ${invoiceData.invoices.length} invoices`);
                    }

                    // Clear status
                    setTimeout(() => setSyncStatus(null), 5000);

                    // Stop monitoring
                    if (interval) {
                        clearInterval(interval);
                        interval = null;
                    }
                } else if (data.status === 'failed') {
                    console.log('❌ Sync failed');
                    setSyncing(false);

                    // Stop monitoring
                    if (interval) {
                        clearInterval(interval);
                        interval = null;
                    }
                }
            } catch (error) {
                console.error('Status check failed:', error);
            }
        };

        // Start monitoring
        if (syncing || syncStatus?.status === 'running') {
            console.log('🔍 Starting status monitor');
            checkStatus();
            interval = setInterval(checkStatus, 2000);
        }

        return () => {
            if (interval) {
                clearInterval(interval);
            }
        };
    }, [syncing, syncStatus?.status]);

    const handleSync = async () => {
        setSyncing(true);

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
                console.log('🚀 Sync started');
            } else {
                console.error('❌ Sync failed to start:', data.error);
                setSyncing(false);
            }
        } catch (error) {
            console.error('❌ Failed to start sync:', error);
            setSyncing(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="e-Facturi" />

            <div className="p-6">
                <div className="flex items-center justify-between mb-6">
                    <h1 className="text-2xl font-bold">e-Facturi</h1>

                    <div className="flex items-center gap-4">
                        {/* Simple sync status */}
                        {syncing && (
                            <div className="flex items-center gap-2 text-sm text-blue-600">
                                <RefreshCw className="h-4 w-4 animate-spin" />
                                <span>{syncStatus?.message || 'Downloading invoices...'}</span>
                            </div>
                        )}

                        <Button
                            onClick={handleSync}
                            disabled={syncing}
                            className="flex items-center gap-2"
                        >
                            {syncing ? (
                                <RefreshCw className="h-4 w-4 animate-spin" />
                            ) : (
                                <RefreshCw className="h-4 w-4" />
                            )}
                            {syncing ? 'Downloading...' : 'Sync Invoices'}
                        </Button>
                    </div>
                </div>

                {/* Error display */}
                {syncStatus?.status === 'failed' && (
                    <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded text-red-700">
                        <strong>Sync Failed:</strong> {syncStatus.message}
                    </div>
                )}

                {/* Invoices table */}
                <div className="bg-white rounded-lg border">
                    <div className="p-4 border-b">
                        <h2 className="font-semibold">Invoices ({invoices.length})</h2>
                    </div>

                    {invoices.length === 0 ? (
                        <div className="text-center py-12">
                            <FileText className="h-12 w-12 text-gray-400 mx-auto mb-4" />
                            <p className="text-gray-600">No invoices found</p>
                            <p className="text-sm text-gray-500 mt-1">Click "Sync Invoices" to download from ANAF</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="text-left p-3 text-sm font-medium">Invoice #</th>
                                        <th className="text-left p-3 text-sm font-medium">Date</th>
                                        <th className="text-left p-3 text-sm font-medium">Supplier</th>
                                        <th className="text-left p-3 text-sm font-medium">Customer</th>
                                        <th className="text-left p-3 text-sm font-medium">Amount</th>
                                        <th className="text-left p-3 text-sm font-medium">Type</th>
                                        <th className="text-left p-3 text-sm font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.map((invoice) => (
                                        <tr key={invoice._id} className="border-b hover:bg-gray-50">
                                            <td className="p-3 text-sm">{invoice.invoice_number}</td>
                                            <td className="p-3 text-sm">{invoice.invoice_date || '-'}</td>
                                            <td className="p-3 text-sm">{invoice.supplier_name || '-'}</td>
                                            <td className="p-3 text-sm">{invoice.customer_name || '-'}</td>
                                            <td className="p-3 text-sm">
                                                {invoice.total_amount ? `${invoice.total_amount} ${invoice.currency}` : '-'}
                                            </td>
                                            <td className="p-3 text-sm">
                                                <Badge variant="outline" className="text-xs">
                                                    {invoice.message_type}
                                                </Badge>
                                            </td>
                                            <td className="p-3 text-sm">
                                                <div className="flex gap-1">
                                                    <Button size="sm" variant="outline" className="h-7 px-2">
                                                        <Download className="h-3 w-3" />
                                                    </Button>
                                                    <Button size="sm" variant="outline" className="h-7 px-2">
                                                        <Eye className="h-3 w-3" />
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
        </AppLayout>
    );
}