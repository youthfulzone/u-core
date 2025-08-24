import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { Checkbox } from '@/components/ui/checkbox';
import { Building2, CheckCircle2, XCircle, Clock, Loader2, Trash2 } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

interface CompanyItem {
    id: string;
    cui: string;
    denumire: string;
    status: 'pending' | 'processing' | 'approved';
    type: 'pending' | 'company';
    created_at: string;
    updated_at: string;
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
        pending_queue: number;
        processing_queue: number;
        approved_today: number;
        rejected_today: number;
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
    const { companies, stats, flash } = usePage<PageProps>().props;
    
    const [selectedItems, setSelectedItems] = useState<string[]>([]);
    const [massActionType, setMassActionType] = useState<'approve' | 'reject' | null>(null);
    const [processingItems, setProcessingItems] = useState<Set<string>>(new Set());

    // Get only pending items for selection logic
    const pendingItems = companies.data.filter(item => item.type === 'pending');

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

    const getStatusBadge = (status: string, type: string) => {
        if (type === 'pending') {
            switch (status) {
                case 'pending':
                    return <Badge variant="secondary" className="flex items-center gap-1"><Clock className="h-3 w-3" />În așteptare</Badge>;
                case 'processing':
                    return <Badge variant="default" className="flex items-center gap-1 bg-blue-600"><Loader2 className="h-3 w-3 animate-spin" />Procesare</Badge>;
                default:
                    return <Badge variant="outline">{status}</Badge>;
            }
        } else {
            return <Badge variant="default" className="flex items-center gap-1 bg-green-600"><CheckCircle2 className="h-3 w-3" />Aprobată</Badge>;
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
                            <CardTitle className="text-sm font-medium">În Coadă</CardTitle>
                            <Clock className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.pending_queue}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Procesare</CardTitle>
                            <Loader2 className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.processing_queue}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Aprobate Azi</CardTitle>
                            <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.approved_today}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Respinse Azi</CardTitle>
                            <XCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.rejected_today}</div>
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
                        <div>
                            <CardTitle>Firme ({companies.total})</CardTitle>
                            <CardDescription>
                                CUI-uri în așteptare (sus) și firme aprobate (jos)
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {companies.data.length === 0 ? (
                            <div className="text-center py-8">
                                <p className="text-muted-foreground">Nu au fost găsite firme</p>
                            </div>
                        ) : (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow className="h-8">
                                            <TableHead className="py-2 w-12">
                                                {pendingItems.length > 0 && (
                                                    <Checkbox
                                                        checked={selectedItems.length === pendingItems.length && pendingItems.length > 0}
                                                        onCheckedChange={handleSelectAll}
                                                    />
                                                )}
                                            </TableHead>
                                            <TableHead className="py-2">CUI</TableHead>
                                            <TableHead className="py-2">Denumire</TableHead>
                                            <TableHead className="py-2">Status</TableHead>
                                            <TableHead className="py-2">Data</TableHead>
                                            <TableHead className="py-2">Acțiuni</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {companies.data.map((item) => (
                                            <TableRow key={item.id} className="h-10">
                                                <TableCell className="py-1">
                                                    {item.type === 'pending' && (
                                                        <Checkbox
                                                            checked={selectedItems.includes(item.id)}
                                                            onCheckedChange={(checked) => handleSelectItem(item.id, checked as boolean)}
                                                        />
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-1 font-mono text-sm">{item.cui}</TableCell>
                                                <TableCell className="py-1 text-sm">{item.denumire}</TableCell>
                                                <TableCell className="py-1">{getStatusBadge(item.status, item.type)}</TableCell>
                                                <TableCell className="py-1 text-sm">{new Date(item.created_at).toLocaleDateString('ro-RO')}</TableCell>
                                                <TableCell className="py-1">
                                                    {item.type === 'pending' && (
                                                        <div className="flex gap-1">
                                                            <Button
                                                                size="sm"
                                                                onClick={() => handleApprove(item.id)}
                                                                disabled={processingItems.has(item.id)}
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
                                                                disabled={processingItems.has(item.id)}
                                                                className="h-6 px-2 text-xs"
                                                            >
                                                                {processingItems.has(item.id) ? (
                                                                    <Loader2 className="h-3 w-3 mr-1 animate-spin" />
                                                                ) : (
                                                                    <XCircle className="h-3 w-3 mr-1" />
                                                                )}
                                                                Respinge
                                                            </Button>
                                                        </div>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>

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