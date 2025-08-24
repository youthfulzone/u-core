import { useState, useMemo } from 'react'
import { Head, router } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { SearchableSelect, type SearchableSelectOption } from '@/components/ui/searchable-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { type BreadcrumbItem } from '@/types'
import { FileText, Send, Clock, CheckCircle, XCircle } from 'lucide-react';

interface SpvRequest {
    id: string;
    cif: string;
    company_name?: string;
    document_type: string;
    status: 'pending' | 'completed' | 'failed';
    user: {
        name: string;
    };
    created_at: string;
    processed_at?: string;
    error_message?: string;
}

interface PaginatedRequests {
    data: SpvRequest[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface CifOption {
    cif: string;
    cui: string;
    company_name: string;
    source?: 'company' | 'message';
}

interface Props {
    requests: PaginatedRequests;
    availableCifs: CifOption[];
    documentTypes: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tablou de bord',
        href: '/dashboard',
    },
    {
        title: 'SPV',
        href: '/spv',
    },
    {
        title: 'Cereri',
        href: '/spv/requests',
    },
]

export default function Requests({ requests, availableCifs, documentTypes }: Props) {
    const [selectedCif, setSelectedCif] = useState<string>('')
    const [selectedDocumentType, setSelectedDocumentType] = useState<string>('Fisa Rol') // Auto-selected
    const [isSubmitting, setIsSubmitting] = useState(false)

    // Convert CIF options to searchable select format
    const searchableOptions: SearchableSelectOption[] = useMemo(() => {
        return availableCifs
            .filter(cifOption => cifOption.cif && cifOption.cif.trim() !== '' && cifOption.cif !== 'null')
            .map(cifOption => {
                // Ensure we have valid CIF/CUI
                const cif = cifOption.cif || cifOption.cui || '';
                const companyName = cifOption.company_name && cifOption.company_name.trim() !== '' 
                    ? cifOption.company_name.trim() 
                    : '';
                
                return {
                    value: cif,
                    label: companyName 
                        ? `${companyName} (CUI: ${cif})`
                        : `CUI: ${cif}`,
                    searchTerms: [
                        cif,
                        cifOption.cui,
                        companyName
                    ].filter(term => term && term.trim() !== '' && term !== 'null')
                }
            })
            .filter(option => option.value && option.value !== '' && option.value !== 'null')
    }, [availableCifs])

    // Convert document types to searchable select format
    const documentTypeOptions: SearchableSelectOption[] = useMemo(() => {
        return Object.entries(documentTypes).map(([key, value]) => ({
            value: key,
            label: value,
            searchTerms: [key, value]
        }))
    }, [documentTypes])

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()
        
        if (!selectedCif || !selectedDocumentType) {
            return
        }

        setIsSubmitting(true)

        router.post('/spv/requests', {
            cif: selectedCif,
            document_type: selectedDocumentType,
        }, {
            onFinish: () => {
                setIsSubmitting(false)
                setSelectedCif('')
                setSelectedDocumentType('')
            }
        })
    }

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'pending':
                return <Badge variant="secondary" className="flex items-center gap-1"><Clock className="h-3 w-3" />În procesare</Badge>
            case 'completed':
                return <Badge variant="default" className="flex items-center gap-1 bg-green-600"><CheckCircle className="h-3 w-3" />Finalizat</Badge>
            case 'failed':
                return <Badge variant="destructive" className="flex items-center gap-1"><XCircle className="h-3 w-3" />Eșuat</Badge>
            default:
                return <Badge variant="outline">{status}</Badge>
        }
    }

    const changePage = (page: number) => {
        router.get('/spv/requests', { page }, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SPV - Cereri" />
            
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
                {/* Request Form */}
                <Card className="relative">
                    <CardHeader>
                        <CardTitle>Trimite cerere nouă</CardTitle>
                        <CardDescription>
                            Selectează CIF-ul și tipul de document pentru a trimite o cerere către ANAF
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit}>
                            <div className="flex items-center gap-3">
                                <SearchableSelect
                                    options={searchableOptions}
                                    value={selectedCif}
                                    onValueChange={setSelectedCif}
                                    placeholder="Selectează sau caută CUI/Nume companie"
                                    searchPlaceholder="Caută după CUI sau nume companie..."
                                    emptyMessage="Nu au fost găsite companii"
                                    className="w-[500px]"
                                />
                                <SearchableSelect
                                    options={documentTypeOptions}
                                    value={selectedDocumentType}
                                    onValueChange={setSelectedDocumentType}
                                    placeholder="Selectează tipul"
                                    searchPlaceholder="Caută tip document..."
                                    emptyMessage="Nu au fost găsite tipuri"
                                    className="w-[180px]"
                                />
                                <Button 
                                    type="submit" 
                                    disabled={!selectedCif || !selectedDocumentType || isSubmitting}
                                    className="flex items-center gap-2 whitespace-nowrap"
                                >
                                    <Send className="h-4 w-4" />
                                    {isSubmitting ? 'Se trimite...' : 'Trimite'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20 opacity-30 pointer-events-none" />
                </Card>

                {/* Requests Log Table */}
                <Card className="relative">
                    <CardHeader>
                        <CardTitle>Istoricul cererilor ({requests.total})</CardTitle>
                        <CardDescription>
                            Lista tuturor cererilor trimise către ANAF
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {requests.data.length === 0 ? (
                            <div className="text-center py-8">
                                <p className="text-muted-foreground">Nu au fost găsite cereri trimise către ANAF</p>
                            </div>
                        ) : (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow className="h-8">
                                            <TableHead className="py-2">Companie</TableHead>
                                            <TableHead className="py-2">CIF</TableHead>
                                            <TableHead className="py-2">Document</TableHead>
                                            <TableHead className="py-2">Status</TableHead>
                                            <TableHead className="py-2">Utilizator</TableHead>
                                            <TableHead className="py-2">Data cererii</TableHead>
                                            <TableHead className="py-2">Data procesării</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {requests.data.map((request) => (
                                            <TableRow key={request.id} className="h-10">
                                                <TableCell className="py-1 text-sm">{request.company_name || '-'}</TableCell>
                                                <TableCell className="py-1 font-mono text-sm">{request.cif}</TableCell>
                                                <TableCell className="py-1 text-sm">{request.document_type}</TableCell>
                                                <TableCell className="py-1">{getStatusBadge(request.status)}</TableCell>
                                                <TableCell className="py-1 text-sm">{request.user.name}</TableCell>
                                                <TableCell className="py-1 text-sm">{new Date(request.created_at).toLocaleString('ro-RO')}</TableCell>
                                                <TableCell className="py-1 text-sm">
                                                    {request.processed_at ? new Date(request.processed_at).toLocaleString('ro-RO') : '-'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>

                                {/* Pagination */}
                                {requests.last_page > 1 && (
                                    <div className="flex justify-between items-center mt-4">
                                        <div className="text-sm text-muted-foreground">
                                            Afișează {((requests.current_page - 1) * requests.per_page) + 1} - {Math.min(requests.current_page * requests.per_page, requests.total)} din {requests.total} rezultate
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => changePage(requests.current_page - 1)}
                                                disabled={requests.current_page <= 1}
                                            >
                                                Anterior
                                            </Button>
                                            <div className="flex items-center gap-1">
                                                {Array.from({ length: Math.min(5, requests.last_page) }, (_, i) => {
                                                    const page = Math.max(1, Math.min(requests.last_page - 4, requests.current_page - 2)) + i;
                                                    return (
                                                        <Button
                                                            key={page}
                                                            variant={page === requests.current_page ? "default" : "outline"}
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
                                                onClick={() => changePage(requests.current_page + 1)}
                                                disabled={requests.current_page >= requests.last_page}
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