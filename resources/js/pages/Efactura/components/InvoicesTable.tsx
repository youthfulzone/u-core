import React from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Download, Eye, RefreshCw, FileText, FileDown } from 'lucide-react';

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

interface InvoicesTableProps {
    invoices: Invoice[];
    downloadingPdf: string | null;
    generatingPdf: string | null;
    onDownloadPDF: (invoiceId: string, hasPdf: boolean) => void;
    onViewXML: (invoiceId: string) => void;
}

export default function InvoicesTable({
    invoices,
    downloadingPdf,
    generatingPdf,
    onDownloadPDF,
    onViewXML
}: InvoicesTableProps) {
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

    return (
        <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
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
                                                    onClick={() => onDownloadPDF(invoice._id, invoice.has_pdf)}
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
                                                    onClick={() => onViewXML(invoice._id)}
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
    );
}