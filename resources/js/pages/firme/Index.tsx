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
import { Building2, CheckCircle2, XCircle, Clock, Loader2, Trash2, RefreshCw, Play, Pause, Lock, Unlock, Check, X } from 'lucide-react';
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
    source_api?: 'anaf' | 'vies';
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
    const [verifyingItems, setVerifyingItems] = useState<Map<string, 'loading' | 'success' | 'error'>>(new Map());
    const [lockResults, setLockResults] = useState<Map<string, 'success' | 'error'>>(new Map());
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [pendingActions, setPendingActions] = useState<Map<string, 'verify' | 'lock' | 'unlock'>>(new Map());
    const [suppressFlashMessages, setSuppressFlashMessages] = useState(false);

    // Watch for flash message changes and suppress them when we have pending button actions
    useEffect(() => {
        if (flash && (flash.success || flash.error) && pendingActions.size > 0) {
            setSuppressFlashMessages(true);
            
            // Clear pending actions and reset suppress flag after a delay
            setPendingActions(new Map());
            setTimeout(() => {
                setSuppressFlashMessages(false);
            }, 100);
        } else if (flash && (flash.success || flash.error)) {
            // No pending actions, allow normal flash messages
            setSuppressFlashMessages(false);
        }
    }, [flash, pendingActions.size]);

    // Get only items that need review (all items can be approved/rejected)
    const pendingItems = companies.data;
    
    // Check if there are companies that need processing (not approved)
    const hasPendingCompanies = companies.data.some(item => 
        item.status !== 'approved' && 
        (item.status === 'pending_data' || item.status === 'processing' || !item.denumire || item.denumire === 'Se încarcă...')
    );

    // Clear visual feedback states when companies data changes
    useEffect(() => {
        // Clear any visual feedback for items that are no longer in the current page
        const currentIds = new Set(companies.data.map(item => item.id));
        
        setVerifyingItems(prev => {
            const newMap = new Map();
            prev.forEach((value, key) => {
                if (currentIds.has(key)) {
                    newMap.set(key, value);
                }
            });
            return newMap;
        });
        
        setLockResults(prev => {
            const newMap = new Map();
            prev.forEach((value, key) => {
                if (currentIds.has(key)) {
                    newMap.set(key, value);
                }
            });
            return newMap;
        });
    }, [companies.data]);

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
        setPendingActions(prev => new Map(prev).set(itemId, 'lock'));
        
        const startTime = Date.now();
        
        router.post('/firme/lock', { item_id: itemId }, {
            preserveState: false,
            preserveScroll: true,
            onSuccess: () => {
                // Ensure loading shows for at least 2 seconds, then show success
                const elapsed = Date.now() - startTime;
                const remainingTime = Math.max(0, 2000 - elapsed);
                
                setTimeout(() => {
                    setLockingItems(prev => {
                        const newSet = new Set(prev);
                        newSet.delete(itemId);
                        return newSet;
                    });
                    setLockResults(prev => new Map(prev).set(itemId, 'success'));
                    // Clear success state after 2 seconds
                    setTimeout(() => {
                        setLockResults(prev => {
                            const newMap = new Map(prev);
                            newMap.delete(itemId);
                            return newMap;
                        });
                    }, 2000);
                }, remainingTime);
            },
            onError: () => {
                // Ensure loading shows for at least 2 seconds, then show error
                const elapsed = Date.now() - startTime;
                const remainingTime = Math.max(0, 2000 - elapsed);
                
                setTimeout(() => {
                    setLockingItems(prev => {
                        const newSet = new Set(prev);
                        newSet.delete(itemId);
                        return newSet;
                    });
                    setLockResults(prev => new Map(prev).set(itemId, 'error'));
                    // Clear error state after 2 seconds
                    setTimeout(() => {
                        setLockResults(prev => {
                            const newMap = new Map(prev);
                            newMap.delete(itemId);
                            return newMap;
                        });
                    }, 2000);
                }, remainingTime);
            }
        });
    };

    const handleUnlock = (itemId: string) => {
        setLockingItems(prev => new Set(prev).add(itemId));
        setPendingActions(prev => new Map(prev).set(itemId, 'unlock'));
        
        const startTime = Date.now();
        
        router.post('/firme/unlock', { item_id: itemId }, {
            preserveState: false,
            preserveScroll: true,
            onSuccess: () => {
                // Ensure loading shows for at least 2 seconds, then show success
                const elapsed = Date.now() - startTime;
                const remainingTime = Math.max(0, 2000 - elapsed);
                
                setTimeout(() => {
                    setLockingItems(prev => {
                        const newSet = new Set(prev);
                        newSet.delete(itemId);
                        return newSet;
                    });
                    setLockResults(prev => new Map(prev).set(itemId, 'success'));
                    // Clear success state after 2 seconds
                    setTimeout(() => {
                        setLockResults(prev => {
                            const newMap = new Map(prev);
                            newMap.delete(itemId);
                            return newMap;
                        });
                    }, 2000);
                }, remainingTime);
            },
            onError: () => {
                // Ensure loading shows for at least 2 seconds, then show error
                const elapsed = Date.now() - startTime;
                const remainingTime = Math.max(0, 2000 - elapsed);
                
                setTimeout(() => {
                    setLockingItems(prev => {
                        const newSet = new Set(prev);
                        newSet.delete(itemId);
                        return newSet;
                    });
                    setLockResults(prev => new Map(prev).set(itemId, 'error'));
                    // Clear error state after 2 seconds
                    setTimeout(() => {
                        setLockResults(prev => {
                            const newMap = new Map(prev);
                            newMap.delete(itemId);
                            return newMap;
                        });
                    }, 2000);
                }, remainingTime);
            }
        });
    };

    const handleVerify = (itemId: string) => {
        setVerifyingItems(prev => new Map(prev).set(itemId, 'loading'));
        setPendingActions(prev => new Map(prev).set(itemId, 'verify'));
        
        const startTime = Date.now();
        
        router.post('/firme/verify', { item_id: itemId }, {
            preserveState: false,
            preserveScroll: true,
            onSuccess: () => {
                // Ensure loading shows for at least 2 seconds, then show success
                const elapsed = Date.now() - startTime;
                const remainingTime = Math.max(0, 2000 - elapsed);
                
                setTimeout(() => {
                    setVerifyingItems(prev => new Map(prev).set(itemId, 'success'));
                    // Clear success state after 2 seconds
                    setTimeout(() => {
                        setVerifyingItems(prev => {
                            const newMap = new Map(prev);
                            newMap.delete(itemId);
                            return newMap;
                        });
                    }, 2000);
                }, remainingTime);
            },
            onError: () => {
                // Ensure loading shows for at least 2 seconds, then show error
                const elapsed = Date.now() - startTime;
                const remainingTime = Math.max(0, 2000 - elapsed);
                
                setTimeout(() => {
                    setVerifyingItems(prev => new Map(prev).set(itemId, 'error'));
                    // Clear error state after 2 seconds
                    setTimeout(() => {
                        setVerifyingItems(prev => {
                            const newMap = new Map(prev);
                            newMap.delete(itemId);
                            return newMap;
                        });
                    }, 2000);
                }, remainingTime);
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
                {/* Flash Messages - Only show for non-button actions (like approve/reject) */}
                {flash?.success && !suppressFlashMessages && (
                    <Alert className="border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                        <CheckCircle2 className="h-4 w-4" />
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}

                {flash?.error && !suppressFlashMessages && (
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
                                                    <span className="block truncate">
                                                        {item.cui}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="py-1 text-sm" style={{ width: '320px' }}>
                                                    <div className="flex items-center gap-2" style={{ maxWidth: '320px' }}>
                                                        <Lock className={`h-3 w-3 text-gray-500 dark:text-gray-400 flex-shrink-0 ${item.locked ? 'opacity-100' : 'opacity-0'}`} />
                                                        {item.source_api === 'vies' && (
                                                            <Badge variant="outline" className="text-[10px] px-1 py-0 h-4 flex-shrink-0" style={{ fontSize: '10px', lineHeight: '1' }}>VIES</Badge>
                                                        )}
                                                        {!item.denumire || item.denumire === 'Se încarcă...' ? (
                                                            <span className="animate-pulse text-muted-foreground truncate">Se încarcă...</span>
                                                        ) : (
                                                            <span title={item.denumire || ''} className={`truncate ${item.locked ? 'text-gray-600 dark:text-gray-300 font-medium' : ''}`}>{item.denumire}</span>
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
                                                                onClick={() => handleVerify(item.id)}
                                                                disabled={verifyingItems.get(item.id) === 'loading'}
                                                                className={`h-6 px-2 text-xs w-[80px] flex items-center justify-center gap-1 transition-all duration-300 ${
                                                                    verifyingItems.get(item.id) === 'success' 
                                                                        ? 'bg-green-500 hover:bg-green-600 text-white border-green-500' 
                                                                        : verifyingItems.get(item.id) === 'error'
                                                                        ? 'bg-red-500 hover:bg-red-600 text-white border-red-500'
                                                                        : 'hover:bg-blue-50 hover:border-blue-400 hover:text-blue-700'
                                                                }`}
                                                            >
                                                                {verifyingItems.get(item.id) === 'loading' ? (
                                                                    <Loader2 className="h-3 w-3 animate-spin" />
                                                                ) : verifyingItems.get(item.id) === 'success' ? (
                                                                    <Check className="h-3 w-3" />
                                                                ) : verifyingItems.get(item.id) === 'error' ? (
                                                                    <X className="h-3 w-3" />
                                                                ) : (
                                                                    <RefreshCw className="h-3 w-3" />
                                                                )}
                                                                {verifyingItems.get(item.id) === 'success' ? 'OK' : 
                                                                 verifyingItems.get(item.id) === 'error' ? 'Eroare' : 
                                                                 'Verifică'}
                                                            </Button>
                                                        )}
                                                        
                                                        {/* Lock/Unlock button */}
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => item.locked ? handleUnlock(item.id) : handleLock(item.id)}
                                                            disabled={lockingItems.has(item.id)}
                                                            className={`h-6 px-2 text-xs w-[110px] flex items-center justify-center gap-1 transition-all duration-300 ${
                                                                lockResults.get(item.id) === 'success'
                                                                    ? 'bg-green-500 hover:bg-green-600 text-white border-green-500'
                                                                    : lockResults.get(item.id) === 'error'
                                                                    ? 'bg-red-500 hover:bg-red-600 text-white border-red-500'
                                                                    : item.locked 
                                                                    ? 'border-gray-400 text-gray-600 hover:bg-orange-50 hover:border-orange-400 hover:text-orange-700' 
                                                                    : 'hover:bg-gray-50 hover:border-gray-400 hover:text-gray-700'
                                                            }`}
                                                        >
                                                            {lockingItems.has(item.id) ? (
                                                                <Loader2 className="h-3 w-3 animate-spin" />
                                                            ) : lockResults.get(item.id) === 'success' ? (
                                                                <Check className="h-3 w-3" />
                                                            ) : lockResults.get(item.id) === 'error' ? (
                                                                <X className="h-3 w-3" />
                                                            ) : item.locked ? (
                                                                <Unlock className="h-3 w-3" />
                                                            ) : (
                                                                <Lock className="h-3 w-3" />
                                                            )}
                                                            {lockResults.get(item.id) === 'success' ? 'OK' :
                                                             lockResults.get(item.id) === 'error' ? 'Eroare' :
                                                             item.locked ? 'Deblochează' : 'Blochează'}
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