import React from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { RotateCcw, Trash2, RefreshCw } from 'lucide-react';

interface SyncStatus {
    is_syncing?: boolean;
    status?: string;
    current_company?: number;
    total_companies?: number;
    cui?: string;
    company_name?: string;
    current_invoice?: number;
    total_invoices_for_company?: number;
    invoice_identifier?: string;
    total_processed?: number;
    test_mode?: boolean;
    last_error?: string;
    total_errors?: number;
}

interface SyncManagementProps {
    syncing: boolean;
    syncStatus: SyncStatus | null;
    clearingDatabase: boolean;
    onSyncMessages: () => void;
    onClearDatabase: () => void;
}

export default function SyncManagement({
    syncing,
    syncStatus,
    clearingDatabase,
    onSyncMessages,
    onClearDatabase
}: SyncManagementProps) {
    return (
        <div className="space-y-4">
            {/* Action Buttons */}
            <div className="flex items-center gap-2">
                <Button
                    onClick={onSyncMessages}
                    disabled={syncing}
                    variant="default"
                    size="sm"
                    className="gap-1"
                >
                    <RotateCcw className={`h-3 w-3 ${syncing ? 'animate-spin' : ''}`} />
                    {syncing ? 'Sincronizează...' : 'Sincronizare facturi'}
                </Button>
                <Button
                    onClick={onClearDatabase}
                    disabled={clearingDatabase}
                    variant="destructive"
                    size="sm"
                    className="gap-1"
                >
                    <Trash2 className="h-3 w-3" />
                    {clearingDatabase ? 'Șterge...' : 'Golire DB'}
                </Button>
            </div>

            {/* Sync Status Display */}
            {(syncStatus?.is_syncing || syncStatus?.status === 'completed' || syncStatus?.status === 'failed') && (
                <div className="flex items-center justify-between mt-2 pt-2 border-t text-xs transition-all duration-300">
                    <div className="flex items-center gap-3">
                        {syncStatus?.is_syncing && (
                            <RefreshCw className="h-3 w-3 animate-spin text-blue-500" />
                        )}
                        <span className="text-muted-foreground">Sync:</span>

                        {/* Company Progress */}
                        {syncStatus?.total_companies && syncStatus.total_companies > 0 && (
                            <>
                                <span className="font-medium text-blue-600">
                                    Company {syncStatus?.current_company || 0}/{syncStatus?.total_companies}
                                </span>
                                <span className="text-muted-foreground">•</span>
                            </>
                        )}

                        {/* Company Name and CUI */}
                        {syncStatus?.current_company && syncStatus.current_company > 0 && (
                            <>
                                <span className="font-mono text-xs bg-gray-100 px-1 rounded">{syncStatus?.cui}</span>
                                <span className="font-medium truncate max-w-[120px]" title={syncStatus?.company_name}>
                                    {syncStatus?.company_name || '-'}
                                </span>
                                <span className="text-muted-foreground">•</span>
                            </>
                        )}

                        {/* Invoice Progress */}
                        {syncStatus?.total_invoices_for_company && syncStatus.total_invoices_for_company > 0 && (
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
                        {syncStatus?.total_processed && syncStatus.total_processed > 0 && (
                            <div className="flex items-center gap-2">
                                <div className="text-xs text-green-600 font-medium">
                                    {syncStatus.total_processed} processed
                                </div>
                            </div>
                        )}

                        {/* Company progress indicator */}
                        {syncStatus?.current_company && syncStatus.current_company > 0 && syncStatus?.total_companies && syncStatus.total_companies > 0 && (
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
                             syncStatus?.status === 'starting' ? 'Pornire...' : 'Inactiv'}
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
            {syncStatus?.total_errors && syncStatus.total_errors > 0 && (
                <div className="mt-1 text-xs text-orange-600">
                    {syncStatus.total_errors} error(s) encountered during sync
                </div>
            )}
        </div>
    );
}