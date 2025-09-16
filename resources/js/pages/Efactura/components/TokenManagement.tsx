import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CheckCircle, RefreshCw, Play } from 'lucide-react';

interface TokenStatus {
    has_token: boolean;
    status: 'active' | 'expiring_warning' | 'expiring_soon' | 'no_token';
    token_id?: string;
    issued_at?: string;
    expires_at?: string;
    days_until_expiry?: number;
    days_since_issued?: number;
    can_refresh?: boolean;
    days_until_refresh?: number;
    usage_count?: number;
    last_used_at?: string;
    message: string;
}

interface TokenManagementProps {
    hasCredentials: boolean;
    tokenStatus: TokenStatus;
    onAuthenticate: () => void;
    onRefreshToken: () => void;
    loading: boolean;
}

export default function TokenManagement({
    hasCredentials,
    tokenStatus,
    onAuthenticate,
    onRefreshToken,
    loading
}: TokenManagementProps) {
    const formatDate = (dateString?: string) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        const day = date.getDate().toString().padStart(2, '0');
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const year = date.getFullYear();
        return `${day}.${month}.${year}`;
    };

    if (!hasCredentials) {
        return (
            <div className="text-center">
                <Badge variant="destructive" className="mb-2">Fără credențiale</Badge>
                <p className="text-sm text-muted-foreground">Credențialele ANAF nu sunt configurate</p>
            </div>
        );
    }

    if (!tokenStatus.has_token) {
        return (
            <div className="text-center">
                <Badge variant="secondary" className="mb-4">Gata pentru autentificare</Badge>
                <Button
                    onClick={onAuthenticate}
                    disabled={loading}
                    size="sm"
                    className="gap-2"
                >
                    <Play className="h-4 w-4" />
                    {loading ? 'Se autentifică...' : 'Autentificare ANAF'}
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <Badge
                        className="border-green-200 bg-green-50 hover:bg-green-100 text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300 flex items-center gap-1"
                    >
                        <CheckCircle className="h-3 w-3" />
                        Activ
                    </Badge>
                    <span className="text-sm font-medium">
                        Expiră în: <span className="font-semibold">{Math.floor(tokenStatus.days_until_expiry || 0)}</span> zile / {formatDate(tokenStatus.expires_at)}
                    </span>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        onClick={onRefreshToken}
                        disabled={!tokenStatus.can_refresh || loading}
                        variant="outline"
                        size="sm"
                        className="gap-1"
                    >
                        <RefreshCw className={`h-3 w-3 ${loading ? 'animate-spin' : ''}`} />
                        {loading ? 'Se reîmprospătează...' : 'Reîmprospătează'}
                    </Button>
                </div>
            </div>
        </div>
    );
}