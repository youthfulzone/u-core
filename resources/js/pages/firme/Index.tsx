import { Head, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { Checkbox } from '@/components/ui/checkbox';
import { Building2, CheckCircle2, XCircle, Clock, Loader2, Trash2, RefreshCw, Play, Pause, Lock, Unlock } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

interface CompanyItem {
    id: string;
    cui: string;
    denumire: string | null;
    status: 'pending_data' | 'processing' | 'active' | 'data_not_found' | 'failed' | 'approved';
    type: 'company';
    created_at: string;
    updated_at: string;
    synced_at?: string | null;
    locked?: boolean;
}

interface PaginatedCompanies {
    data: CompanyItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface PageProps {
    companies: PaginatedCompanies;
    stats: {
        total_companies: number;
        pending_data: number;
        processing: number;
        active: number;
        data_not_found: number;
        failed: number;
    };
    flash?: {
        success?: string;
        error?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { href: '/dashboard', title: 'Dashboard' },
    { href: '/firme', title: 'Firme', active: true }
];

export default function FirmeIndex() {
    const { companies: initialCompanies, stats: initialStats, flash } = usePage<PageProps>().props;
    
    const [companies, setCompanies] = useState(initialCompanies);
    const [stats, setStats] = useState(initialStats);
    const [selectedItems, setSelectedItems] = useState<string[]>([]);
    const [massActionType, setMassActionType] = useState<'approve' | 'reject' | null>(null);
    const [processingItems, setProcessingItems] = useState<Set<string>>(new Set());
    const [lockingItems, setLockingItems] = useState<Set<string>>(new Set());
    const [autoRefresh, setAutoRefresh] = useState(true);

    // Get only items that need review (all items can be approved/rejected)
    const pendingItems = companies.data;
    
    // Check if there are companies that need processing (not approved)
    const hasPendingCompanies = companies.data.some(item => 
        item.status !== 'approved' && 
        (item.status === 'pending_data' || item.status === 'processing' || !item.denumire || item.denumire === 'Se încarcă...')
    );

    // Auto-refresh and processing functionality - only when there are pending companies
    useEffect(() => {
        if (!autoRefresh || !hasPendingCompanies) return;

        const statusInterval = setInterval(async () => {
            try {
                const response = await fetch('/firme/status');
                if (response.ok) {
                    const data = await response.json();
                    setCompanies(data.companies);
                    setStats(data.stats);
                }
            } catch (error) {
                console.error('Failed to fetch status:', error);
            }
        }, 3000); // Refresh status every 3 seconds

        const processInterval = setInterval(async () => {
            try {
                const response = await fetch('/firme/process-next', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                });
                
                if (response.ok) {
                    const result = await response.json();
                    if (result.success && result.processed_cui) {
                        console.log('✅ Processed CUI:', result.processed_cui);
                    }
                }
            } catch (error) {
                console.error('Failed to process next CUI:', error);
            }
        }, 2000); // Process one CUI every 2 seconds

        return () => {
            clearInterval(statusInterval);
            clearInterval(processInterval);
        };
    }, [autoRefresh, hasPendingCompanies]);

    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedItems(pendingItems.map(item => item.id));
        } else {
            setSelectedItems([]);
        }
    };

    const handleSelectItem = (itemId: string, checked: boolean) => {
        if (checked) {
            setSelectedItems(prev => [...prev, itemId]);
        } else {
            setSelectedItems(prev => prev.filter(id => id !== itemId));
        }
    };


    const handleApprove = (itemId: string) => {
        setProcessingItems(prev => new Set(prev).add(itemId));
        router.post('/firme/approve', { item_id: itemId }, {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => {
                setProcessingItems(prev => {
                    const newSet = new Set(prev);
                    newSet.delete(itemId);
                    return newSet;
                });
            }
        });
    };

    const handleReject = (itemId: string) => {
        setProcessingItems(prev => new Set(prev).add(itemId));
        router.post('/firme/reject', { item_id: itemId }, {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => {
                setProcessingItems(prev => {
                    const newSet = new Set(prev);
                    newSet.delete(itemId);
                    return newSet;
                });
            }
        });
    };

    const handleLock = (itemId: string) => {
        setLockingItems(prev => new Set(prev).add(itemId));
        router.post('/firme/lock', { item_id: itemId }, {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => {
                setLockingItems(prev => {
                    const newSet = new Set(prev);
                    newSet.delete(itemId);
                    return newSet;
                });
            }
        });
    };

    const handleUnlock = (itemId: string) => {
        setLockingItems(prev => new Set(prev).add(itemId));
        router.post('/firme/unlock', { item_id: itemId }, {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => {
                setLockingItems(prev => {
                    const newSet = new Set(prev);
                    newSet.delete(itemId);
                    return newSet;
                });
            }
        });
    };

    const handleMassAction = (action: 'approve' | 'reject') => {
        if (selectedItems.length === 0) return;
        
        setMassActionType(action);
        router.post('/firme/mass-action', { 
            action, 
            item_ids: selectedItems 
        }, {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => {
                setSelectedItems([]);
                setMassActionType(null);
            }
        });
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'pending_data':
                return <Badge variant="secondary" className="flex items-center gap-1 animate-pulse"><Clock className="h-3 w-3" />Așteaptă date...</Badge>;
            case 'processing':
                return <Badge variant="default" className="flex items-center gap-1 bg-blue-600"><Loader2 className="h-3 w-3 animate-spin" />Se încarcă...</Badge>;
            case 'active':
                return <Badge variant="default" className="flex items-center gap-1 bg-green-600"><CheckCircle2 className="h-3 w-3" />Activă</Badge>;
            case 'approved':
                return <Badge variant="default" className="flex items-center gap-1 bg-emerald-600"><CheckCircle2 className="h-3 w-3" />Aprobată</Badge>;
            case 'data_not_found':
                return <Badge variant="outline" className="flex items-center gap-1"><XCircle className="h-3 w-3" />Date negăsite</Badge>;
            case 'failed':
                return <Badge variant="destructive" className="flex items-center gap-1"><XCircle className="h-3 w-3" />Eroare</Badge>;
            default:
                return <Badge variant="outline">{status}</Badge>;
        }
    };

    const changePage = (page: number) => {
        router.get('/firme', { page }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Firme" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
                {/* Flash Messages */}
                {flash?.success && (
                    <Alert className="border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                        <CheckCircle2 className="h-4 w-4" />
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {flash?.error && (
                    <Alert className="border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                        <XCircle className="h-4 w-4" />
                        <AlertDescription>{flash.error}</AlertDescription>
                    </Alert>
                )}

                {/* Stats Cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Firme</CardTitle>
                            <Building2 className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.total_companies}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Așteaptă Date</CardTitle>
                            <Clock className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.pending_data}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">În Procesare</CardTitle>
                            <Loader2 className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.processing}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Active</CardTitle>
                            <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.active}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Eșuate</CardTitle>
                            <XCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.failed}</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Mass Actions for Pending Items */}
                {selectedItems.length > 0 && (
                    <Card>
                        <CardContent className="py-3">
                            <div className="flex items-center justify-between">
                                <span className="text-sm">
                                    {selectedItems.length === 1 
                                        ? '1 element selectat' 
                                        : `${selectedItems.length} elemente selectate`
                                    }
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        onClick={() => handleMassAction('approve')}
                                        disabled={massActionType === 'approve'}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        {massActionType === 'approve' ? (
                                            <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                                        ) : (
                                            <CheckCircle2 className="mr-1 h-3 w-3" />
                                        )}
                                        Aprobă Tot
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        onClick={() => handleMassAction('reject')}
                                        disabled={massActionType === 'reject'}
                                    >
                                        {massActionType === 'reject' ? (
                                            <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                                        ) : (
                                            <Trash2 className="mr-1 h-3 w-3" />
                                        )}
                                        Respinge Tot
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Unified Companies Table */}
                <Card className="relative">
                    <CardHeader>
                        <div className="flex justify-between items-center">
                            <div>
                                <CardTitle>Firme ({companies.total})</CardTitle>
                                <CardDescription>
                                    Toate firmele înregistrate - datele se încarcă automat
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setAutoRefresh(!autoRefresh)}
                                    className={autoRefresh && hasPendingCompanies ? "border-green-500 text-green-600" : 
                                              !hasPendingCompanies ? "border-gray-300 text-gray-400" : ""}
                                    disabled={!hasPendingCompanies}
                                >
                                    {autoRefresh && hasPendingCompanies ? (
                                        <Pause className="h-4 w-4 mr-1" />
                                    ) : (
                                        <Play className="h-4 w-4 mr-1" />
                                    )}
                                    {!hasPendingCompanies ? 'Auto-Refresh (Inactiv)' : 
                                     autoRefresh ? 'Oprește Auto-Refresh' : 'Pornește Auto-Refresh'}
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {companies.data.length === 0 ? (
                            <div className="text-center py-8">
                                <p className="text-muted-foreground">Nu au fost găsite firme</p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-hidden">
                                    <Table className="w-full" style={{ tableLayout: 'fixed' }}>
                                        <TableHeader>
                                            <TableRow className="h-8">
                                                <TableHead className="py-2" style={{ width: '48px' }}>
                                                    {pendingItems.length > 0 && (
                                                        <Checkbox
                                                            checked={selectedItems.length === pendingItems.length && pendingItems.length > 0}
                                                            onCheckedChange={handleSelectAll}
                                                        />
                                                    )}
                                                </TableHead>
                                                <TableHead className="py-2" style={{ width: '112px' }}>CUI</TableHead>
                                                <TableHead className="py-2" style={{ width: '320px' }}>Denumire</TableHead>
                                                <TableHead className="py-2" style={{ width: '144px' }}>Status</TableHead>
                                                <TableHead className="py-2" style={{ width: '96px' }}>Data</TableHead>
                                                <TableHead className="py-2" style={{ width: '224px' }}>Acțiuni</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                    <TableBody>
                                        {companies.data.map((item) => (
                                            <TableRow 
                                                key={item.id} 
                                                className={`h-10 transition-colors ${
                                                    item.locked 
                                                        ? 'bg-gray-100/70 dark:bg-gray-800/30 border-l-4 border-l-gray-400 hover:bg-gray-200/70 dark:hover:bg-gray-700/50' 
                                                        : 'hover:bg-gray-50 dark:hover:bg-gray-800/50'
                                                }`}
                                            >
                                                <TableCell className="py-1" style={{ width: '48px' }}>
                                                    <Checkbox
                                                        checked={selectedItems.includes(item.id)}
                                                        onCheckedChange={(checked) => handleSelectItem(item.id, checked as boolean)}
                                                    />
                                                </TableCell>
                                                <TableCell className="py-1 font-mono text-sm truncate" style={{ width: '112px' }}>
                                                    <div className="relative pl-4">
                                                        {item.locked && (
                                                            <Lock className="absolute left-0 top-1/2 -translate-y-1/2 h-3 w-3 text-gray-500 dark:text-gray-400" />
                                                        )}
                                                        <span className={`block truncate ${item.locked ? 'text-gray-600 dark:text-gray-300 font-medium' : ''}`}>
                                                            {item.cui}
                                                        </span>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="py-1 text-sm" style={{ width: '320px' }}>
                                                    <div className="truncate">
                                                        {!item.denumire || item.denumire === 'Se încarcă...' ? (
                                                            <span className="animate-pulse text-muted-foreground">Se încarcă...</span>
                                                        ) : (
                                                            <span title={item.denumire || ''}>{item.denumire}</span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="py-1" style={{ width: '144px' }}>{getStatusBadge(item.status)}</TableCell>
                                                <TableCell className="py-1 text-sm" style={{ width: '96px' }}>{new Date(item.created_at).toLocaleDateString('ro-RO')}</TableCell>
                                                <TableCell className="py-1" style={{ width: '224px' }}>
                                                    <div className="flex gap-1">
                                                        {/* Accept/Reject buttons - only show if not approved */}
                                                        {item.status !== 'approved' && (
                                                            <>
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() => handleApprove(item.id)}
                                                                    disabled={processingItems.has(item.id) || item.locked}
                                                                    className="bg-green-600 hover:bg-green-700 h-6 px-2 text-xs"
                                                                >
                                                                    {processingItems.has(item.id) ? (
                                                                        <Loader2 className="h-3 w-3 mr-1 animate-spin" />
                                                                    ) : (
                                                                        <CheckCircle2 className="h-3 w-3 mr-1" />
                                                                    )}
                                                                    Acceptă
                                                                </Button>
                                                                <Button
                                                                    size="sm"
                                                                    variant="destructive"
                                                                    onClick={() => handleReject(item.id)}
                                                                    disabled={processingItems.has(item.id) || item.locked}
                                                                    className="h-6 px-2 text-xs"
                                                                >
                                                                    {processingItems.has(item.id) ? (
                                                                        <Loader2 className="h-3 w-3 mr-1 animate-spin" />
                                                                    ) : (
                                                                        <XCircle className="h-3 w-3 mr-1" />
                                                                    )}
                                                                    Respinge
                                                                </Button>
                                                            </>
                                                        )}
                                                        
                                                        {/* Verifica button - always show for approved companies, or non-approved with data */}
                                                        {(item.status === 'approved' || item.denumire !== 'Se încarcă...') && (
                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() => {
                                                                    // Trigger re-verification of company data
                                                                    router.post('/firme/verify', { item_id: item.id });
                                                                }}
                                                                className="h-6 px-2 text-xs"
                                                            >
                                                                <RefreshCw className="h-3 w-3 mr-1" />
                                                                Verifică
                                                            </Button>
                                                        )}
                                                        
                                                        {/* Lock/Unlock button */}
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => item.locked ? handleUnlock(item.id) : handleLock(item.id)}
                                                            disabled={lockingItems.has(item.id)}
                                                            className={`h-6 px-2 text-xs min-w-[85px] ${item.locked ? 'border-gray-400 text-gray-600 hover:bg-gray-100' : ''}`}
                                                        >
                                                            {lockingItems.has(item.id) ? (
                                                                <Loader2 className="h-3 w-3 mr-1 animate-spin" />
                                                            ) : item.locked ? (
                                                                <Unlock className="h-3 w-3 mr-1" />
                                                            ) : (
                                                                <Lock className="h-3 w-3 mr-1" />
                                                            )}
                                                            {item.locked ? 'Deblochează' : 'Blochează'}
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                </div>

                                {/* Pagination */}
                                {companies.last_page > 1 && (
                                    <div className="flex justify-between items-center mt-4">
                                        <div className="text-sm text-muted-foreground">
                                            Afișează {((companies.current_page - 1) * companies.per_page) + 1} - {Math.min(companies.current_page * companies.per_page, companies.total)} din {companies.total} rezultate
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => changePage(companies.current_page - 1)}
                                                disabled={companies.current_page <= 1}
                                            >
                                                Anterior
                                            </Button>
                                            <div className="flex items-center gap-1">
                                                {Array.from({ length: Math.min(5, companies.last_page) }, (_, i) => {
                                                    const page = Math.max(1, Math.min(companies.last_page - 4, companies.current_page - 2)) + i;
                                                    return (
                                                        <Button
                                                            key={page}
                                                            variant={page === companies.current_page ? "default" : "outline"}
                                                            size="sm"
                                                            onClick={() => changePage(page)}
                                                            className="w-8 h-8 p-0"
                                                        >
                                                            {page}
                                                        </Button>
                                                    );
                                                })}
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => changePage(companies.current_page + 1)}
                                                disabled={companies.current_page >= companies.last_page}
                                            >
                                                Următor
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20 opacity-30 pointer-events-none" />
                </Card>
            </div>
        </AppLayout>
    );
}