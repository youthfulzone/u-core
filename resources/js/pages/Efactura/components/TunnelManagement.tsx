import React from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CheckCircle, XCircle, RefreshCw } from 'lucide-react';

interface TunnelStatus {
    running: boolean;
    tunnel_url?: string;
    port?: number;
    pid?: number;
}

interface TunnelManagementProps {
    tunnelStatus: TunnelStatus | null;
    tunnelLoading: boolean;
    onTunnelControl: (action: 'start' | 'stop') => void;
}

export default function TunnelManagement({
    tunnelStatus,
    tunnelLoading,
    onTunnelControl
}: TunnelManagementProps) {
    return (
        <div className="border-t pt-3 mt-3">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <span className="text-xs text-muted-foreground">Tunnel OAuth:</span>
                    {tunnelStatus?.running ? (
                        <Badge
                            className="border-green-200 bg-green-50 hover:bg-green-100 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300 flex items-center gap-1 text-xs"
                        >
                            <CheckCircle className="w-3 h-3" />
                            Activ
                        </Badge>
                    ) : (
                        <Badge
                            className="border-red-200 bg-red-50 hover:bg-red-100 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300 flex items-center gap-1 text-xs"
                        >
                            <XCircle className="w-3 h-3" />
                            Oprit
                        </Badge>
                    )}
                    {tunnelStatus?.tunnel_url && (
                        <span className="text-xs text-muted-foreground">
                            {tunnelStatus.tunnel_url}
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-1">
                    <Button
                        onClick={() => onTunnelControl('start')}
                        disabled={tunnelLoading || tunnelStatus?.running}
                        size="sm"
                        variant="outline"
                        className="text-xs h-6 px-2"
                    >
                        {tunnelLoading ? <RefreshCw className="w-3 h-3 animate-spin" /> : 'Start'}
                    </Button>
                    <Button
                        onClick={() => onTunnelControl('stop')}
                        disabled={tunnelLoading || !tunnelStatus?.running}
                        size="sm"
                        variant="outline"
                        className="text-xs h-6 px-2"
                    >
                        {tunnelLoading ? <RefreshCw className="w-3 h-3 animate-spin" /> : 'Stop'}
                    </Button>
                </div>
            </div>
        </div>
    );
}