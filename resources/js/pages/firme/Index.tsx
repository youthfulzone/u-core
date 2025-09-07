import { Head, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { Building2, CheckCircle2, XCircle, Clock, Loader2, Trash2, RefreshCw, Play, Pause, Lock, Unlock, Check, X, Zap, ExternalLink, Plus, Database } from 'lucide-react';
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
    source_api?: 'anaf' | 'vies' | 'targetare';
    tax_category?: string | null;
    employees_current?: number | null;
    vat?: boolean;
    split_vat?: boolean;
    checkout_vat?: boolean;
    manual_added?: boolean;
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
    targetare?: {
        remainingRequests?: number | null;
        apiAvailable?: boolean;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { href: '/dashboard', title: 'Dashboard' },
    { href: '/firme', title: 'Firme', active: true }
];

export default function FirmeIndex() {
    const { companies: initialCompanies, stats: initialStats, flash, targetare } = usePage<PageProps>().props;
    
    const [companies, setCompanies] = useState(initialCompanies);
    const [stats, setStats] = useState(initialStats);
    const [processingItems, setProcessingItems] = useState<Set<string>>(new Set());
    const [lockingItems, setLockingItems] = useState<Set<string>>(new Set());
    const [verifyingItems, setVerifyingItems] = useState<Map<string, 'loading' | 'success' | 'error'>>(new Map());
    const [lockResults, setLockResults] = useState<Map<string, 'success' | 'error'>>(new Map());
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [pendingActions, setPendingActions] = useState<Map<string, 'verify' | 'lock' | 'unlock'>>(new Map());
    const [suppressFlashMessages, setSuppressFlashMessages] = useState(false);
    const [newCui, setNewCui] = useState('');
    const [isAddingCompany, setIsAddingCompany] = useState(false);
    const [deletingItems, setDeletingItems] = useState<Set<string>>(new Set());
    const [confirmingDelete, setConfirmingDelete] = useState<Set<string>>(new Set());
    const [isClearingDatabase, setIsClearingDatabase] = useState(false);
    const [confirmingClearDatabase, setConfirmingClearDatabase] = useState(false);

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

    
    // Check if there are companies that need processing (including approved ones that still need data)
    const hasPendingCompanies = companies.data.some(item => 
        item.status === 'pending_data' || 
        item.status === 'processing' || 
        !item.denumire || 
        item.denumire === 'Se încarcă...'
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

    // Refresh data when component mounts or user navigates to this page
    useEffect(() => {
        const refreshData = async () => {
            try {
                const response = await fetch('/firme/status');
                if (response.ok) {
                    const data = await response.json();
                    setCompanies(data.companies);
                    setStats(data.stats);
                }
            } catch (error) {
                console.error('Failed to refresh data:', error);
            }
        };

        // Refresh data when component first loads
        refreshData();

        // Also refresh when window gains focus (for tab switching)
        const handleFocus = () => refreshData();
        window.addEventListener('focus', handleFocus);
        
        return () => window.removeEventListener('focus', handleFocus);
    }, []); // Empty dependency array means this runs once on mount

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


    const getStatusBadge = (status: string, item?: CompanyItem) => {
        // Check if it's a manually added company
        if (status === 'approved' && item?.manual_added) {
            return <Badge variant="default" className="flex items-center gap-1 bg-blue-500"><CheckCircle2 className="h-3 w-3" />Manual</Badge>;
        }
        
        switch (status) {
            case 'pending_data':
                return <Badge variant="secondary" className="flex items-center gap-1 animate-pulse"><Clock className="h-3 w-3" />Așteaptă date...</Badge>;
            case 'processing':
                return <Badge variant="default" className="flex items-center gap-1 bg-blue-600"><Loader2 className="h-3 w-3 animate-spin" />Se încarcă...</Badge>;
            case 'active':
                return <Badge variant="default" className="flex items-center gap-1 bg-green-600"><CheckCircle2 className="h-3 w-3" />Activă</Badge>;
            case 'approved':
                return <Badge variant="default" className="flex items-center gap-1 bg-emerald-600"><CheckCircle2 className="h-3 w-3" />Automat</Badge>;
            case 'data_not_found':
                return <Badge variant="outline" className="flex items-center gap-1"><XCircle className="h-3 w-3" />Date negăsite</Badge>;
            case 'failed':
                return <Badge variant="destructive" className="flex items-center gap-1"><XCircle className="h-3 w-3" />Eroare</Badge>;
            default:
                return <Badge variant="outline">{status}</Badge>;
        }
    };

    const formatNameForTargetareUrl = (name: string) => {
        return name
            .toLowerCase()
            .replace(/[^a-z0-9\s]/g, '') // Remove special characters
            .replace(/\s+/g, '-') // Replace spaces with hyphens
            .replace(/-+/g, '-') // Replace multiple hyphens with single
            .replace(/^-|-$/g, ''); // Remove leading/trailing hyphens
    };

    const getTargetareUrl = (cui: string, name: string) => {
        const formattedName = formatNameForTargetareUrl(name);
        return `https://targetare.ro/${cui}/${formattedName}`;
    };

    const getVatStatus = (item: CompanyItem) => {
        if (!item.vat && item.source_api !== 'vies') {
            return '-';
        }
        
        let status = 'Da';
        
        // For VIES companies, always show "Da - VIES" since they're VAT registered
        if (item.source_api === 'vies') {
            return 'Da - VIES';
        }
        
        // Check for TVA la Incasare for non-VIES companies
        if (item.split_vat || item.checkout_vat) {
            status += ' - Încasare';
        }
        
        return status;
    };

    const changePage = (page: number) => {
        router.get('/firme', { page }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleAddCompany = () => {
        if (!newCui || newCui.length < 6 || newCui.length > 9 || !/^[0-9]+$/.test(newCui)) {
            alert('CUI-ul trebuie să conțină între 6 și 9 cifre.');
            return;
        }

        setIsAddingCompany(true);
        router.post('/firme/add', { cui: newCui }, {
            preserveState: false,
            preserveScroll: true,
            onSuccess: () => {
                setNewCui('');
                setIsAddingCompany(false);
            },
            onError: () => {
                setIsAddingCompany(false);
            }
        });
    };

    const handleDeleteCompany = (itemId: string) => {
        setConfirmingDelete(prev => new Set(prev).add(itemId));
    };

    const confirmDeleteCompany = (itemId: string) => {
        setConfirmingDelete(prev => {
            const newSet = new Set(prev);
            newSet.delete(itemId);
            return newSet;
        });
        
        setDeletingItems(prev => new Set(prev).add(itemId));
        router.post('/firme/delete', { item_id: itemId }, {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => {
                setDeletingItems(prev => {
                    const newSet = new Set(prev);
                    newSet.delete(itemId);
                    return newSet;
                });
            }
        });
    };

    const cancelDeleteCompany = (itemId: string) => {
        setConfirmingDelete(prev => {
            const newSet = new Set(prev);
            newSet.delete(itemId);
            return newSet;
        });
    };

    const handleClearDatabase = () => {
        setConfirmingClearDatabase(true);
    };

    const confirmClearDatabase = () => {
        setConfirmingClearDatabase(false);
        setIsClearingDatabase(true);
        
        router.delete('/firme/clear-all', {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => {
                setIsClearingDatabase(false);
            }
        });
    };

    const cancelClearDatabase = () => {
        setConfirmingClearDatabase(false);
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
                            <CardTitle className="text-sm font-medium">Eșuate</CardTitle>
                            <XCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.failed}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Targetare API</CardTitle>
                            <Zap className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {targetare?.remainingRequests ?? 'N/A'}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {targetare?.apiAvailable ? 'Apeluri rămase' : 'API indisponibil'}
                            </p>
                        </CardContent>
                    </Card>
                </div>


                {/* Unified Companies Table */}
                <Card className="relative">
                    <CardHeader>
                        <div className="flex justify-between items-center">
                            <div className="flex items-center gap-4">
                                <div>
                                    <CardTitle>Firme ({companies.total})</CardTitle>
                                    <CardDescription>
                                        Toate firmele înregistrate - datele se încarcă automat
                                    </CardDescription>
                                </div>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="text"
                                        value={newCui}
                                        onChange={(e) => setNewCui(e.target.value.replace(/\D/g, ''))}
                                        placeholder="CUI"
                                        maxLength={9}
                                        className="w-24 px-2 py-1 text-sm border rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        onKeyPress={(e) => {
                                            if (e.key === 'Enter') {
                                                handleAddCompany();
                                            }
                                        }}
                                        disabled={isAddingCompany}
                                    />
                                    <Button
                                        onClick={handleAddCompany}
                                        disabled={isAddingCompany || !newCui}
                                        size="sm"
                                        className="h-7 px-2 text-xs"
                                    >
                                        {isAddingCompany ? (
                                            <Loader2 className="h-3 w-3 animate-spin" />
                                        ) : (
                                            <Plus className="h-3 w-3" />
                                        )}
                                        <span className="ml-1">Adaugă</span>
                                    </Button>
                                </div>
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
                                
                                {/* Clear Database Button */}
                                <div className="flex items-center gap-1">
                                    {confirmingClearDatabase ? (
                                        <>
                                            <Button
                                                size="sm"
                                                onClick={confirmClearDatabase}
                                                disabled={isClearingDatabase}
                                                className="bg-red-600 hover:bg-red-700 text-white border-red-600"
                                            >
                                                {isClearingDatabase ? (
                                                    <Loader2 className="h-4 w-4 animate-spin mr-1" />
                                                ) : (
                                                    <Check className="h-4 w-4 mr-1" />
                                                )}
                                                Confirmă
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={cancelClearDatabase}
                                                disabled={isClearingDatabase}
                                            >
                                                <X className="h-4 w-4 mr-1" />
                                                Anulează
                                            </Button>
                                        </>
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={handleClearDatabase}
                                            disabled={isClearingDatabase}
                                            className="border-red-300 text-red-600 hover:bg-red-50 hover:border-red-400"
                                        >
                                            <Database className="h-4 w-4 mr-1" />
                                            Golește DB
                                        </Button>
                                    )}
                                </div>
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
                                                <TableHead className="py-2" style={{ width: '88px' }}>CUI</TableHead>
                                                <TableHead className="py-2" style={{ width: '220px' }}>Denumire</TableHead>
                                                <TableHead className="py-2" style={{ width: '80px' }}>Impozitare</TableHead>
                                                <TableHead className="py-2" style={{ width: '70px' }}>Angajați</TableHead>
                                                <TableHead className="py-2" style={{ width: '100px' }}>TVA</TableHead>
                                                <TableHead className="py-2" style={{ width: '144px' }}>Status</TableHead>
                                                <TableHead className="py-2" style={{ width: '96px' }}>Data</TableHead>
                                                <TableHead className="py-2" style={{ width: '292px' }}>Acțiuni</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                    <TableBody>
                                        {companies.data.map((item) => (
                                            <TableRow 
                                                key={item.id} 
                                                className={`h-10 transition-colors ${
                                                    item.manual_added
                                                        ? 'bg-blue-50/50 dark:bg-blue-900/20 border-l-4 border-l-blue-400 hover:bg-blue-100/50 dark:hover:bg-blue-800/30'
                                                        : item.locked 
                                                        ? 'bg-gray-100/70 dark:bg-gray-800/30 border-l-4 border-l-gray-400 hover:bg-gray-200/70 dark:hover:bg-gray-700/50' 
                                                        : 'hover:bg-gray-50 dark:hover:bg-gray-800/50'
                                                }`}
                                            >
                                                <TableCell className="py-1 font-mono text-sm truncate" style={{ width: '88px' }}>
                                                    <span className="block truncate">
                                                        {item.cui}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="py-1 text-sm" style={{ width: '220px' }}>
                                                    <div className="flex items-center gap-2" style={{ maxWidth: '220px' }}>
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
                                                <TableCell className="py-1 text-sm" style={{ width: '80px' }}>
                                                    <span className="text-xs text-gray-600 dark:text-gray-400 truncate capitalize">
                                                        {item.tax_category === 'income' ? 'Venit' : (item.tax_category || '-')}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="py-1 text-sm" style={{ width: '70px' }}>
                                                    <span className="text-xs text-gray-600 dark:text-gray-400 text-center">
                                                        {item.employees_current || '-'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="py-1 text-sm" style={{ width: '100px' }}>
                                                    <span className={`text-xs font-medium ${item.vat ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400'}`}>
                                                        {getVatStatus(item)}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="py-1" style={{ width: '144px' }}>{getStatusBadge(item.status, item)}</TableCell>
                                                <TableCell className="py-1 text-sm" style={{ width: '96px' }}>{new Date(item.created_at).toLocaleDateString('ro-RO')}</TableCell>
                                                <TableCell className="py-1" style={{ width: '260px' }}>
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
                                                        
                                                        {/* Targetare redirect button - show when company has valid name and is not from VIES */}
                                                        {item.denumire && item.denumire !== 'Se încarcă...' && item.source_api !== 'vies' && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => window.open(getTargetareUrl(item.cui, item.denumire), '_blank')}
                                                                className="h-6 px-2 text-xs w-[32px] flex items-center justify-center"
                                                                title="Deschide în Targetare.ro"
                                                            >
                                                                <ExternalLink className="h-3 w-3" />
                                                            </Button>
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
                                                        
                                                        {/* Delete button - only for manually added companies */}
                                                        {item.manual_added && (
                                                            <div className="flex gap-1">
                                                                {confirmingDelete.has(item.id) ? (
                                                                    <>
                                                                        {/* Confirm delete - green checkmark */}
                                                                        <Button
                                                                            size="sm"
                                                                            onClick={() => confirmDeleteCompany(item.id)}
                                                                            disabled={deletingItems.has(item.id)}
                                                                            className="h-6 px-2 text-xs bg-green-500 hover:bg-green-600 text-white border-green-500"
                                                                            title="Confirmare ștergere"
                                                                        >
                                                                            {deletingItems.has(item.id) ? (
                                                                                <Loader2 className="h-3 w-3 animate-spin" />
                                                                            ) : (
                                                                                <Check className="h-3 w-3" />
                                                                            )}
                                                                        </Button>
                                                                        {/* Cancel delete - red X */}
                                                                        <Button
                                                                            size="sm"
                                                                            onClick={() => cancelDeleteCompany(item.id)}
                                                                            disabled={deletingItems.has(item.id)}
                                                                            className="h-6 px-2 text-xs bg-red-500 hover:bg-red-600 text-white border-red-500"
                                                                            title="Anulare ștergere"
                                                                        >
                                                                            <X className="h-3 w-3" />
                                                                        </Button>
                                                                    </>
                                                                ) : (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="destructive"
                                                                        onClick={() => handleDeleteCompany(item.id)}
                                                                        disabled={deletingItems.has(item.id)}
                                                                        className="h-6 px-2 text-xs"
                                                                        title="Șterge companie"
                                                                    >
                                                                        <Trash2 className="h-3 w-3" />
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        )}
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