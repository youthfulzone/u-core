import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Icon } from '@/components/icon'
import { SpvRequest } from './types'

interface RequestsListProps {
    requests: SpvRequest[]
}

export default function RequestsList({ requests }: RequestsListProps) {
    const getStatusBadge = (status: string) => {
        const variants: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
            'pending': 'outline',
            'processed': 'default',
            'error': 'destructive',
        }
        return variants[status] || 'secondary'
    }

    const getStatusIcon = (status: string) => {
        const icons: Record<string, string> = {
            'pending': 'Clock',
            'processed': 'CheckCircle',
            'error': 'XCircle',
        }
        return icons[status] || 'Circle'
    }

    return (
        <div className="space-y-3">
            {requests.length === 0 ? (
                <Card>
                    <CardContent className="flex items-center justify-center h-32">
                        <div className="text-center">
                            <Icon name="FileText" className="h-12 w-12 text-muted-foreground mx-auto mb-2" />
                            <p className="text-muted-foreground">No document requests found</p>
                            <p className="text-sm text-muted-foreground">
                                Submit a new document request to get started
                            </p>
                        </div>
                    </CardContent>
                </Card>
            ) : (
                requests.map((request) => (
                    <Card key={request.id}>
                        <CardHeader className="pb-3">
                            <div className="flex items-start justify-between">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <Badge variant={getStatusBadge(request.status)}>
                                            <Icon 
                                                name={getStatusIcon(request.status)} 
                                                className="w-3 h-3 mr-1" 
                                            />
                                            {request.status.charAt(0).toUpperCase() + request.status.slice(1)}
                                        </Badge>
                                        <span className="text-sm text-muted-foreground">
                                            CUI: {request.cui}
                                        </span>
                                    </div>
                                    <CardTitle className="text-lg">{request.tip}</CardTitle>
                                    <CardDescription>
                                        Created: {new Date(request.created_at).toLocaleString('ro-RO')}
                                        {request.processed_at && (
                                            <> • Processed: {request.formatted_processed_at}</>
                                        )}
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-0 space-y-3">
                            {/* Request Parameters */}
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                                {request.an && (
                                    <div>
                                        <span className="text-muted-foreground">Year:</span> {request.an}
                                    </div>
                                )}
                                {request.luna && (
                                    <div>
                                        <span className="text-muted-foreground">Month:</span> {request.luna}
                                    </div>
                                )}
                                {request.motiv && (
                                    <div className="col-span-2">
                                        <span className="text-muted-foreground">Reason:</span> {request.motiv}
                                    </div>
                                )}
                                {request.numar_inregistrare && (
                                    <div className="col-span-2">
                                        <span className="text-muted-foreground">Registration:</span> {request.numar_inregistrare}
                                    </div>
                                )}
                                {request.cui_pui && (
                                    <div>
                                        <span className="text-muted-foreground">PUI CUI:</span> {request.cui_pui}
                                    </div>
                                )}
                            </div>

                            {/* ANAF Response */}
                            {request.anaf_id_solicitare && (
                                <div className="bg-muted p-3 rounded-lg">
                                    <div className="flex items-center gap-2 mb-2">
                                        <Icon name="ExternalLink" className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-sm font-medium">ANAF Response</span>
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Request ID: {request.anaf_id_solicitare}
                                    </div>
                                </div>
                            )}

                            {/* Error Message */}
                            {request.error_message && (
                                <div className="bg-destructive/10 border border-destructive/20 p-3 rounded-lg">
                                    <div className="flex items-center gap-2 mb-1">
                                        <Icon name="AlertCircle" className="h-4 w-4 text-destructive" />
                                        <span className="text-sm font-medium text-destructive">Error</span>
                                    </div>
                                    <p className="text-sm text-destructive">{request.error_message}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))
            )}
        </div>
    )
}