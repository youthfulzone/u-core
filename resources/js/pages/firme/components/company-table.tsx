import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Building2, CheckCircle2, XCircle, Clock, Loader2, Trash2, Lock, Unlock, Check, X, Zap } from 'lucide-react';
import { memo, useCallback } from 'react';

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

interface CompanyTableProps {
    companies: CompanyItem[];
    processingItems: Set<string>;
    lockingItems: Set<string>;
    verifyingItems: Map<string, 'loading' | 'success' | 'error'>;
    lockResults: Map<string, 'success' | 'error'>;
    deletingItems: Set<string>;
    confirmingDelete: Set<string>;
    onProcess: (id: string) => void;
    onApprove: (id: string) => void;
    onReject: (id: string) => void;
    onLock: (id: string) => void;
    onUnlock: (id: string) => void;
    onVerify: (id: string) => void;
    onDelete: (id: string) => void;
    onConfirmDelete: (id: string) => void;
    onCancelDelete: (id: string) => void;
}

const CompanyRow = memo(function CompanyRow({ 
    company, 
    isProcessing, 
    isLocking, 
    verifyingState, 
    lockResult, 
    isDeleting, 
    isConfirmingDelete,
    onProcess,
    onApprove,
    onReject,
    onLock,
    onUnlock,
    onVerify,
    onDelete,
    onConfirmDelete,
    onCancelDelete 
}: {
    company: CompanyItem;
    isProcessing: boolean;
    isLocking: boolean;
    verifyingState?: 'loading' | 'success' | 'error';
    lockResult?: 'success' | 'error';
    isDeleting: boolean;
    isConfirmingDelete: boolean;
    onProcess: (id: string) => void;
    onApprove: (id: string) => void;
    onReject: (id: string) => void;
    onLock: (id: string) => void;
    onUnlock: (id: string) => void;
    onVerify: (id: string) => void;
    onDelete: (id: string) => void;
    onConfirmDelete: (id: string) => void;
    onCancelDelete: (id: string) => void;
}) {
    const handleProcess = useCallback(() => onProcess(company.id), [onProcess, company.id]);
    const handleApprove = useCallback(() => onApprove(company.id), [onApprove, company.id]);
    const handleReject = useCallback(() => onReject(company.id), [onReject, company.id]);
    const handleLock = useCallback(() => onLock(company.id), [onLock, company.id]);
    const handleUnlock = useCallback(() => onUnlock(company.id), [onUnlock, company.id]);
    const handleVerify = useCallback(() => onVerify(company.id), [onVerify, company.id]);
    const handleDelete = useCallback(() => onDelete(company.id), [onDelete, company.id]);
    const handleConfirmDelete = useCallback(() => onConfirmDelete(company.id), [onConfirmDelete, company.id]);
    const handleCancelDelete = useCallback(() => onCancelDelete(company.id), [onCancelDelete, company.id]);

    const getStatusBadge = () => {
        const statusConfig = {
            'pending_data': { color: 'bg-yellow-100 text-yellow-800', icon: Clock, label: 'În așteptare' },
            'processing': { color: 'bg-blue-100 text-blue-800', icon: Loader2, label: 'Procesare' },
            'active': { color: 'bg-green-100 text-green-800', icon: CheckCircle2, label: 'Activ' },
            'data_not_found': { color: 'bg-gray-100 text-gray-800', icon: XCircle, label: 'Date negăsite' },
            'failed': { color: 'bg-red-100 text-red-800', icon: XCircle, label: 'Eșuat' },
            'approved': { color: 'bg-green-100 text-green-800', icon: CheckCircle2, label: 'Aprobat' },
        };
        
        const config = statusConfig[company.status];
        const Icon = config.icon;
        
        return (
            <Badge className={`${config.color} flex items-center gap-1`}>
                <Icon className="h-3 w-3" />
                {config.label}
            </Badge>
        );
    };

    return (
        <TableRow key={company.id}>
            <TableCell className="font-medium">{company.cui}</TableCell>
            <TableCell>
                {company.denumire || (
                    <span className="text-gray-400 italic">Date nedisponibile</span>
                )}
            </TableCell>
            <TableCell>{getStatusBadge()}</TableCell>
            <TableCell>
                {company.source_api && (
                    <Badge variant="outline" className="uppercase">
                        {company.source_api}
                    </Badge>
                )}
            </TableCell>
            <TableCell>
                <div className="flex items-center gap-2">
                    {/* Action buttons */}
                    {company.status === 'pending_data' && (
                        <Button
                            size="sm"
                            onClick={handleProcess}
                            disabled={isProcessing}
                        >
                            {isProcessing ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : (
                                <>
                                    <Zap className="h-4 w-4 mr-1" />
                                    Procesează
                                </>
                            )}
                        </Button>
                    )}
                    
                    {(company.status === 'active' || company.status === 'data_not_found') && (
                        <>
                            <Button size="sm" variant="outline" onClick={handleApprove}>
                                <Check className="h-4 w-4 mr-1" />
                                Aprobă
                            </Button>
                            <Button size="sm" variant="outline" onClick={handleReject}>
                                <X className="h-4 w-4 mr-1" />
                                Respinge
                            </Button>
                        </>
                    )}
                    
                    {/* Lock/Unlock button */}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={company.locked ? handleUnlock : handleLock}
                        disabled={isLocking}
                    >
                        {isLocking ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : company.locked ? (
                            <>
                                <Unlock className="h-4 w-4 mr-1" />
                                Deblochează
                            </>
                        ) : (
                            <>
                                <Lock className="h-4 w-4 mr-1" />
                                Blochează
                            </>
                        )}
                    </Button>
                    
                    {/* Verify button */}
                    <Button size="sm" variant="outline" onClick={handleVerify}>
                        {verifyingState === 'loading' ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            'Verifică'
                        )}
                    </Button>
                    
                    {/* Delete button */}
                    {isConfirmingDelete ? (
                        <div className="flex gap-1">
                            <Button
                                size="sm"
                                variant="destructive"
                                onClick={handleConfirmDelete}
                                disabled={isDeleting}
                            >
                                {isDeleting ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    'Confirmă'
                                )}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={handleCancelDelete}
                            >
                                Anulează
                            </Button>
                        </div>
                    ) : (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={handleDelete}
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
});

export const CompanyTable = memo(function CompanyTable(props: CompanyTableProps) {
    const {
        companies,
        processingItems,
        lockingItems,
        verifyingItems,
        lockResults,
        deletingItems,
        confirmingDelete,
        onProcess,
        onApprove,
        onReject,
        onLock,
        onUnlock,
        onVerify,
        onDelete,
        onConfirmDelete,
        onCancelDelete,
    } = props;

    if (companies.length === 0) {
        return (
            <div className="text-center py-8 text-gray-500">
                <Building2 className="mx-auto h-12 w-12 mb-4 text-gray-300" />
                <p>Nu există companii înregistrate.</p>
            </div>
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>CUI</TableHead>
                    <TableHead>Denumire</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Sursă</TableHead>
                    <TableHead>Acțiuni</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {companies.map((company) => (
                    <CompanyRow
                        key={company.id}
                        company={company}
                        isProcessing={processingItems.has(company.id)}
                        isLocking={lockingItems.has(company.id)}
                        verifyingState={verifyingItems.get(company.id)}
                        lockResult={lockResults.get(company.id)}
                        isDeleting={deletingItems.has(company.id)}
                        isConfirmingDelete={confirmingDelete.has(company.id)}
                        onProcess={onProcess}
                        onApprove={onApprove}
                        onReject={onReject}
                        onLock={onLock}
                        onUnlock={onUnlock}
                        onVerify={onVerify}
                        onDelete={onDelete}
                        onConfirmDelete={onConfirmDelete}
                        onCancelDelete={onCancelDelete}
                    />
                ))}
            </TableBody>
        </Table>
    );
});