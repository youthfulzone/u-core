import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Clock, Calendar, PlayCircle, StopCircle, CheckCircle2, XCircle, AlertTriangle, Info } from 'lucide-react';

interface AutoSyncConfig {
    enabled: boolean;
    schedule_time: string;
    sync_days: number;
    timezone: string;
    last_run: string | null;
    next_run: string | null;
    status: 'idle' | 'running' | 'completed' | 'failed';
    last_error: string | null;
    consecutive_failures: number;
    email_reports: boolean;
    email_recipients: string | null;
    last_report: any;
}

interface AutoSyncStatus {
    status: 'idle' | 'running' | 'completed' | 'failed';
    enabled: boolean;
    last_run: string | null;
    next_run: string | null;
    last_error: string | null;
    consecutive_failures: number;
    last_report: any;
    time_until_next_run: number | null;
}

export default function AutoSyncSettings() {
    const [config, setConfig] = useState<AutoSyncConfig | null>(null);
    const [status, setStatus] = useState<AutoSyncStatus | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [triggering, setTriggering] = useState(false);

    // Fetch current configuration
    useEffect(() => {
        fetchConfig();
        fetchStatus();

        // Update status every 30 seconds
        const interval = setInterval(fetchStatus, 30000);
        return () => clearInterval(interval);
    }, []);

    const fetchConfig = async () => {
        try {
            const response = await fetch('/efactura/auto-sync/config');
            const data = await response.json();
            setConfig(data);
        } catch (error) {
            console.error('Failed to fetch auto-sync config:', error);
        }
    };

    const fetchStatus = async () => {
        try {
            const response = await fetch('/efactura/auto-sync/status');
            const data = await response.json();
            setStatus(data);
        } catch (error) {
            console.error('Failed to fetch auto-sync status:', error);
        }
    };

    const saveConfig = async () => {
        if (!config) return;

        setSaving(true);
        try {
            const response = await fetch('/efactura/auto-sync/config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                },
                body: JSON.stringify(config)
            });

            const data = await response.json();

            if (data.success) {
                setConfig(data.config);
                fetchStatus(); // Update status after config change
            } else {
                console.error('Failed to save config:', data.error);
            }
        } catch (error) {
            console.error('Failed to save auto-sync config:', error);
        } finally {
            setSaving(false);
        }
    };

    const triggerManualSync = async () => {
        setTriggering(true);
        try {
            const response = await fetch('/efactura/auto-sync/trigger', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || ''
                },
                body: JSON.stringify({})
            });

            const data = await response.json();

            if (data.success) {
                console.log('Manual sync triggered:', data.sync_id);
                fetchStatus(); // Update status immediately
            } else {
                console.error('Failed to trigger sync:', data.error);
            }
        } catch (error) {
            console.error('Failed to trigger manual sync:', error);
        } finally {
            setTriggering(false);
        }
    };

    const formatTime = (dateString: string | null) => {
        if (!dateString) return 'Never';
        return new Date(dateString).toLocaleString();
    };

    const formatTimeUntilNext = (seconds: number | null) => {
        if (!seconds || seconds <= 0) return 'Now';

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);

        if (hours > 0) {
            return `${hours}h ${minutes}m`;
        }
        return `${minutes}m`;
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'running':
                return <Badge className="bg-blue-100 text-blue-800"><PlayCircle className="w-3 h-3 mr-1" />Running</Badge>;
            case 'completed':
                return <Badge className="bg-green-100 text-green-800"><CheckCircle2 className="w-3 h-3 mr-1" />Completed</Badge>;
            case 'failed':
                return <Badge className="bg-red-100 text-red-800"><XCircle className="w-3 h-3 mr-1" />Failed</Badge>;
            default:
                return <Badge className="bg-gray-100 text-gray-800"><StopCircle className="w-3 h-3 mr-1" />Idle</Badge>;
        }
    };

    if (!config || !status) {
        return <div>Loading auto-sync settings...</div>;
    }

    return (
        <div className="space-y-6">
            {/* Status Card */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Clock className="w-5 h-5" />
                        Auto-Sync Status
                    </CardTitle>
                    <CardDescription>
                        Current status and next scheduled run
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <Label className="text-sm font-medium">Status</Label>
                            <div className="mt-1">
                                {getStatusBadge(status.status)}
                            </div>
                        </div>
                        <div>
                            <Label className="text-sm font-medium">Last Run</Label>
                            <div className="mt-1 text-sm">{formatTime(status.last_run)}</div>
                        </div>
                        <div>
                            <Label className="text-sm font-medium">Next Run</Label>
                            <div className="mt-1 text-sm">
                                {config.enabled ? (
                                    <>
                                        {formatTime(status.next_run)}
                                        {status.time_until_next_run && (
                                            <div className="text-xs text-gray-500">
                                                in {formatTimeUntilNext(status.time_until_next_run)}
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <span className="text-gray-500">Disabled</span>
                                )}
                            </div>
                        </div>
                    </div>

                    {status.last_error && (
                        <div className="p-3 bg-red-50 border border-red-200 rounded-md">
                            <div className="flex items-center gap-2 text-red-800">
                                <AlertTriangle className="w-4 h-4" />
                                <span className="font-medium">Last Error</span>
                            </div>
                            <p className="mt-1 text-sm text-red-700">{status.last_error}</p>
                            {status.consecutive_failures > 1 && (
                                <p className="mt-1 text-xs text-red-600">
                                    {status.consecutive_failures} consecutive failures
                                </p>
                            )}
                        </div>
                    )}

                    {status.last_report && (
                        <div className="p-3 bg-green-50 border border-green-200 rounded-md">
                            <div className="flex items-center gap-2 text-green-800">
                                <Info className="w-4 h-4" />
                                <span className="font-medium">Last Sync Report</span>
                            </div>
                            <div className="mt-2 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div>
                                    <div className="font-medium text-green-900">Companies</div>
                                    <div className="text-green-700">{status.last_report.companies_with_invoices || 0}</div>
                                </div>
                                <div>
                                    <div className="font-medium text-green-900">Total Invoices</div>
                                    <div className="text-green-700">{status.last_report.summary?.total_invoices || 0}</div>
                                </div>
                                <div>
                                    <div className="font-medium text-green-900">Successful</div>
                                    <div className="text-green-700">{status.last_report.summary?.successful || 0}</div>
                                </div>
                                <div>
                                    <div className="font-medium text-green-900">Success Rate</div>
                                    <div className="text-green-700">{status.last_report.summary?.success_rate || 0}%</div>
                                </div>
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Configuration Card */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Calendar className="w-5 h-5" />
                        Auto-Sync Configuration
                    </CardTitle>
                    <CardDescription>
                        Configure automatic e-Factura synchronization schedule
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center space-x-2">
                        <Switch
                            checked={config.enabled}
                            onCheckedChange={(enabled) => setConfig({...config, enabled})}
                            disabled={saving}
                        />
                        <Label>Enable automatic synchronization</Label>
                    </div>

                    {config.enabled && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pl-6 border-l-2 border-blue-200">
                            <div>
                                <Label htmlFor="schedule_time">Schedule Time (24h format)</Label>
                                <Input
                                    id="schedule_time"
                                    type="time"
                                    value={config.schedule_time}
                                    onChange={(e) => setConfig({...config, schedule_time: e.target.value})}
                                    disabled={saving}
                                />
                            </div>

                            <div>
                                <Label htmlFor="sync_days">Days to Sync</Label>
                                <Select
                                    value={config.sync_days.toString()}
                                    onValueChange={(value) => setConfig({...config, sync_days: parseInt(value)})}
                                    disabled={saving}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="7">7 days</SelectItem>
                                        <SelectItem value="30">30 days</SelectItem>
                                        <SelectItem value="60">60 days</SelectItem>
                                        <SelectItem value="90">90 days</SelectItem>
                                        <SelectItem value="180">180 days</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="timezone">Timezone</Label>
                                <Select
                                    value={config.timezone}
                                    onValueChange={(value) => setConfig({...config, timezone: value})}
                                    disabled={saving}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="Europe/Bucharest">Europe/Bucharest (Romania)</SelectItem>
                                        <SelectItem value="UTC">UTC</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="email_recipients">Email Recipients (optional)</Label>
                                <Input
                                    id="email_recipients"
                                    type="email"
                                    placeholder="admin@example.com"
                                    value={config.email_recipients || ''}
                                    onChange={(e) => setConfig({...config, email_recipients: e.target.value})}
                                    disabled={saving}
                                />
                            </div>
                        </div>
                    )}

                    <div className="flex gap-2 pt-4">
                        <Button
                            onClick={saveConfig}
                            disabled={saving}
                            className="flex items-center gap-2"
                        >
                            {saving ? (
                                <>
                                    <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                    Saving...
                                </>
                            ) : (
                                'Save Configuration'
                            )}
                        </Button>

                        <Button
                            onClick={triggerManualSync}
                            disabled={triggering || status.status === 'running'}
                            variant="outline"
                            className="flex items-center gap-2"
                        >
                            {triggering ? (
                                <>
                                    <div className="w-4 h-4 border-2 border-gray-600 border-t-transparent rounded-full animate-spin" />
                                    Starting...
                                </>
                            ) : (
                                <>
                                    <PlayCircle className="w-4 h-4" />
                                    Run Now
                                </>
                            )}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}