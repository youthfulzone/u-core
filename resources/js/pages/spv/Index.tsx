import { useState, useEffect } from 'react'
import { Head, router } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern'
import { Icon } from '@/components/icon'
import { type BreadcrumbItem } from '@/types'
import { 
    Mail, 
    Clock, 
    Download, 
    Shield, 
    ExternalLink, 
    Info, 
    CheckCircle, 
    RefreshCw, 
    Loader2 
} from 'lucide-react'

interface SpvMessage {
    id: string
    anaf_id: string
    detalii: string
    cif: string
    data_creare: string
    tip: string
    downloaded_at: string | null
    formatted_date_creare: string
}

interface SpvRequest {
    id: string
    tip: string
    cui: string
    status: string
    created_at: string
    formatted_processed_at: string
}

interface SpvIndexProps {
    messages: SpvMessage[]
    requests: SpvRequest[]
    sessionActive: boolean
    sessionExpiry: string | null
    documentTypes: Record<string, string>
    incomeReasons: string[]
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'SPV',
        href: '/spv',
    },
]

export default function SpvIndex({ 
    messages, 
    requests, 
    sessionActive, 
    sessionExpiry,
    documentTypes,
    incomeReasons
}: SpvIndexProps) {
    const [loading, setLoading] = useState(false)
    const [syncDays, setSyncDays] = useState(60)
    const [syncCif, setSyncCif] = useState('')
    const [autoSynced, setAutoSynced] = useState(false)
    const [syncMessage, setSyncMessage] = useState<string | null>(null)
    const [extensionAvailable, setExtensionAvailable] = useState(false)
    const [extensionLastSync, setExtensionLastSync] = useState<string | null>(null)

    // Auto-sync on page load if session is active and no messages exist
    useEffect(() => {
        if (sessionActive && messages.length === 0 && !autoSynced) {
            console.log('Auto-syncing messages on page load...')
            handleSyncMessages()
            setAutoSynced(true)
        }
    }, [sessionActive, messages.length, autoSynced])

    // Extension detection and monitoring
    useEffect(() => {
        const detectExtension = () => {
            // Check for extension API directly
            if (window.anafCookieHelper) {
                console.log('🔌 ANAF Cookie Helper extension API detected!')
                setExtensionAvailable(true)
                setExtensionLastSync(new Date().toISOString())
                return
            }
            
            // Listen for extension load event
            const handleExtensionLoaded = (event: any) => {
                console.log('🔌 ANAF Cookie Helper extension loaded!', event.detail)
                setExtensionAvailable(true)
                setExtensionLastSync(event.detail?.timestamp || new Date().toISOString())
            }
            
            const handleCookiesSynced = (event: any) => {
                console.log('✅ Cookies synced by extension!', event.detail)
                setExtensionLastSync(new Date().toISOString())
            }
            
            window.addEventListener('anaf-extension-loaded', handleExtensionLoaded)
            window.addEventListener('anaf-cookies-synced', handleCookiesSynced)
            
            // Check again after a short delay in case extension loads after this component
            setTimeout(() => {
                if (window.anafCookieHelper && !extensionAvailable) {
                    console.log('🔌 Extension API found on second check')
                    setExtensionAvailable(true)
                    setExtensionLastSync(new Date().toISOString())
                }
            }, 1000)
            
            return () => {
                window.removeEventListener('anaf-extension-loaded', handleExtensionLoaded)
                window.removeEventListener('anaf-cookies-synced', handleCookiesSynced)
            }
        }

        const cleanup = detectExtension()

        // Monitor for extension activity
        const monitorExtension = setInterval(() => {
            // Check if extension API is available
            if (window.anafCookieHelper && !extensionAvailable) {
                setExtensionAvailable(true)
                setExtensionLastSync(new Date().toISOString())
            }
            
            // Check session status to see if extension has provided cookies
            fetch('/api/anaf/session/status')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.session.active && 
                        (data.session.source === 'extension' || data.session.source === 'extension_api')) {
                        if (!extensionAvailable) {
                            setExtensionAvailable(true)
                        }
                        setExtensionLastSync(new Date().toISOString())
                    }
                })
                .catch(error => {
                    // Silent fail
                })
        }, 30000) // Check every 30 seconds

        return () => {
            cleanup?.()
            clearInterval(monitorExtension)
        }
    }, [extensionAvailable])

    // No longer needed - simplified to use only browser session authentication





    // Auto-extract browser cookies for ANAF domain
    const extractAnafCookies = (): Record<string, string> => {
        const cookies: Record<string, string> = {};
        
        // Parse document.cookie for ANAF-related cookies
        if (typeof document !== 'undefined') {
            document.cookie.split(';').forEach(cookie => {
                const [name, value] = cookie.trim().split('=');
                if (name && value && (
                    name.includes('JSESSION') ||
                    name.includes('anaf') ||
                    name.includes('MRH') ||
                    name.includes('F5_') ||
                    name.includes('session')
                )) {
                    cookies[name] = value;
                }
            });
        }
        
        return cookies;
    };

    // Removed - replaced with simplified handleSyncMessages

    // Removed - replaced with simplified handleSyncMessages

    // Removed - all complex authentication methods replaced with simplified session-based approach

    const handleSyncMessages = async () => {
        console.log('Sync button clicked!')
        
        try {
            setLoading(true)
            setSyncMessage('🔄 Checking for ANAF cookies...')
            
            // Step 1: Try to get cookies from extension first
            if (window.anafCookieHelper) {
                console.log('🔌 Extension detected, trying to get cookies automatically...')
                setSyncMessage('🔌 Extension detected! Checking for ANAF cookies...')
                
                const cookieResult = await window.anafCookieHelper.getCookies()
                
                if (cookieResult.success && Object.keys(cookieResult.cookies).length > 0) {
                    console.log('✅ Extension: Found ANAF cookies, syncing to app...')
                    setSyncMessage('✅ Found ANAF cookies! Syncing to application...')
                    
                    // Sync cookies using extension
                    const syncResult = await window.anafCookieHelper.syncCookies()
                    
                    if (syncResult.success) {
                        setSyncMessage('✅ Cookies synced! Getting messages...')
                        
                        // Now try to sync messages
                        const messageResponse = await fetch('/spv/sync-messages', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                            },
                            body: JSON.stringify({
                                days: syncDays,
                                cif: syncCif || undefined
                            })
                        })
                        
                        const messageResult = await messageResponse.json()
                        
                        if (messageResponse.ok && messageResult.success) {
                            setSyncMessage(`✅ Success! Synced ${messageResult.synced_count} new messages (${messageResult.total_messages} total found)`)
                            setTimeout(() => setSyncMessage(null), 5000)
                            router.reload({ only: ['messages', 'requests'] })
                            return
                        }
                    }
                }
                
                // If extension didn't work, fall through to manual authentication
                console.log('ℹ️ Extension: No valid cookies found, requiring manual authentication')
                setSyncMessage('🔐 No valid ANAF cookies found. Opening ANAF for authentication...')
            } else {
                // No extension, try direct approach first
                console.log('ℹ️ No extension detected, trying direct sync...')
                setSyncMessage('🔄 Syncing messages...')
                
                const response = await fetch('/spv/sync-messages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({
                        days: syncDays,
                        cif: syncCif || undefined
                    })
                })
                
                const result = await response.json()
                
                if (response.ok && result.success) {
                    setSyncMessage(`✅ Success! Synced ${result.synced_count} new messages (${result.total_messages} total found)`)
                    setTimeout(() => setSyncMessage(null), 5000)
                    router.reload({ only: ['messages', 'requests'] })
                    return
                }
                
                // If direct approach failed, continue to authentication
                setSyncMessage('🔐 Authentication required. Opening ANAF for authentication...')
            }
            
            // Open ANAF for authentication - extension will auto-capture cookies
            window.open('https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60', '_blank')
            
            setSyncMessage(`🔐 Please authenticate at ANAF in the new tab.

${window.anafCookieHelper ? 'Extension will automatically:' : 'After authentication:'}
1. ${window.anafCookieHelper ? 'Capture your authentication cookies' : 'Use the extension to capture cookies'}
2. ${window.anafCookieHelper ? 'Sync them to this application' : 'Or manually import session data'}
3. ${window.anafCookieHelper ? 'Enable automatic message retrieval' : 'Then sync messages'}

Once authenticated, click "Sync Messages" again.`)
            
            setTimeout(() => setSyncMessage(null), 15000)
            
        } catch (error) {
            console.error('Sync failed:', error)
            setSyncMessage('❌ Sync failed: ' + (error instanceof Error ? error.message : 'Unknown error'))
            setTimeout(() => setSyncMessage(null), 8000)
        } finally {
            setLoading(false)
        }
    }
    
    // Removed old sync function - now using browser session authentication only

    const handleDownload = (messageId: string) => {
        window.open(`/spv/download/${messageId}`, '_blank')
    }

    const getMessageTypeBadge = (tip: string) => {
        const variants: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
            'RECIPISA': 'default',
            'RAPORT': 'secondary',
            'NOTIFICARE': 'outline',
        }
        return variants[tip] || 'secondary'
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SPV - Spatiul Privat Virtual ANAF" />
            
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
                {/* Header Stats Grid */}
                <div className="grid auto-rows-min gap-4 md:grid-cols-4">
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <div className="absolute inset-0 p-4 flex flex-col justify-between">
                            <div className="flex items-center justify-between">
                                <Icon iconNode={Mail} className="h-5 w-5 text-muted-foreground" />
                                <Badge variant={sessionActive ? "default" : "secondary"} className="text-xs">
                                    {sessionActive ? "Active" : "Inactive"}
                                </Badge>
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{messages.length}</div>
                                <p className="text-xs text-muted-foreground">Total Messages</p>
                            </div>
                        </div>
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <div className="absolute inset-0 p-4 flex flex-col justify-between">
                            <div className="flex items-center justify-between">
                                <Icon iconNode={Clock} className="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">
                                    {requests.filter(r => r.status === 'pending').length}
                                </div>
                                <p className="text-xs text-muted-foreground">Pending Requests</p>
                            </div>
                        </div>
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <div className="absolute inset-0 p-4 flex flex-col justify-between">
                            <div className="flex items-center justify-between">
                                <Icon iconNode={Download} className="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">
                                    {messages.filter(m => m.downloaded_at).length}
                                </div>
                                <p className="text-xs text-muted-foreground">Downloaded</p>
                            </div>
                        </div>
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <div className="absolute inset-0 p-4 flex flex-col justify-between">
                            <div className="flex items-center justify-between">
                                <Icon iconNode={Shield} className="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div>
                                <div className="text-lg font-bold">
                                    {sessionActive ? "Connected" : "Not Connected"}
                                    {extensionAvailable && (
                                        <span className="text-xs text-green-600 dark:text-green-400 ml-1">🔌</span>
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {extensionAvailable ? "ANAF Session (Extension)" : "ANAF Session"}
                                </p>
                            </div>
                        </div>
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>

                {/* Main Content Area */}
                <div className="relative min-h-[60vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <div className="absolute inset-0 p-6 overflow-y-auto">
                        <div className="space-y-6">
                            {/* Sync Messages Section */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Icon iconNode={RefreshCw} className="h-5 w-5" />
                                        Sync ANAF Messages
                                    </CardTitle>
                                    <CardDescription>
                                        Retrieve and synchronize messages from ANAF Spatiul Privat Virtual
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    {/* Extension Status Alert */}
                                    <Alert>
                                        <Icon iconNode={Info} className="h-4 w-4" />
                                        <AlertDescription>
                                            {extensionAvailable ? (
                                                <div className="p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                                                    <div className="flex items-center gap-2 mb-2">
                                                        <span className="text-green-600 dark:text-green-400">🔌</span>
                                                        <strong className="text-green-800 dark:text-green-200">ANAF Cookie Helper Extension Active</strong>
                                                    </div>
                                                    <p className="text-sm text-green-700 dark:text-green-300 mb-2">
                                                        Extension will automatically handle ANAF authentication and sync messages seamlessly.
                                                    </p>
                                                    {extensionLastSync && (
                                                        <p className="text-xs text-green-600 dark:text-green-400">
                                                            Last sync: {new Date(extensionLastSync).toLocaleString()}
                                                        </p>
                                                    )}
                                                </div>
                                            ) : (
                                                <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                                                    <div className="flex items-center gap-2 mb-2">
                                                        <span className="text-blue-600 dark:text-blue-400">🌐</span>
                                                        <strong className="text-blue-800 dark:text-blue-200">Browser Authentication Mode</strong>
                                                    </div>
                                                    <p className="text-sm text-blue-700 dark:text-blue-300 mb-2">
                                                        System will open ANAF website for authentication when needed.
                                                    </p>
                                                    <p className="text-xs text-blue-600 dark:text-blue-400">
                                                        💡 Install ANAF Cookie Helper extension for automatic sync
                                                    </p>
                                                </div>
                                            )}
                                        </AlertDescription>
                                    </Alert>

                                    {/* Sync Controls */}
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <Label htmlFor="days" className="text-sm font-medium">Days to sync</Label>
                                            <Input
                                                id="days"
                                                type="number"
                                                min="1"
                                                max="365"
                                                value={syncDays}
                                                onChange={(e) => setSyncDays(parseInt(e.target.value) || 60)}
                                                placeholder="60"
                                                className="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="cif" className="text-sm font-medium">CIF Filter (optional)</Label>
                                            <Input
                                                id="cif"
                                                value={syncCif}
                                                onChange={(e) => setSyncCif(e.target.value)}
                                                placeholder="Filter by CIF"
                                                className="mt-1"
                                            />
                                        </div>
                                        <div className="flex items-end">
                                            <Button 
                                                onClick={handleSyncMessages}
                                                disabled={loading}
                                                className="w-full"
                                                size="lg"
                                                variant={extensionAvailable ? "default" : "outline"}
                                            >
                                                {loading ? (
                                                    <>
                                                        <Icon iconNode={Loader2} className="mr-2 h-4 w-4 animate-spin" />
                                                        Syncing...
                                                    </>
                                                ) : (
                                                    <>
                                                        <Icon iconNode={extensionAvailable ? CheckCircle : RefreshCw} className="mr-2 h-4 w-4" />
                                                        Sync Messages
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>


                            {/* Manual input removed - simplified interface */}
                            {false && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Import ANAF Data</CardTitle>
                                        <CardDescription>
                                            Import session cookies OR paste the entire ANAF response for automatic extraction
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <Alert>
                                            <Icon iconNode={Info} className="h-4 w-4" />
                                            <AlertDescription>
                                                <div className="space-y-2">
                                                    <p><strong>🚀 Multiple Import Methods:</strong></p>
                                                    
                                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                                                        <div className="p-3 border rounded-lg">
                                                            <p className="font-semibold text-sm">Method 1: Cookie Import</p>
                                                            <ol className="list-decimal list-inside space-y-1 text-xs mt-2">
                                                                <li>Open ANAF and authenticate</li>
                                                                <li>Press <kbd>F12</kbd> → <strong>Application</strong> → <strong>Cookies</strong></li>
                                                                <li>Copy cookie values in format:</li>
                                                            </ol>
                                                            <div className="p-2 bg-gray-100 dark:bg-gray-800 rounded text-xs font-mono mt-2">
                                                                JSESSIONID=ABC123...<br/>
                                                                MRHSession=XYZ789...
                                                            </div>
                                                        </div>
                                                        
                                                        <div className="p-3 border rounded-lg bg-green-50 dark:bg-green-900/20">
                                                            <p className="font-semibold text-sm">Method 2: Smart Capture ⭐</p>
                                                            <ol className="list-decimal list-inside space-y-1 text-xs mt-2">
                                                                <li>Open ANAF and authenticate</li>
                                                                <li>Copy the <strong>ENTIRE response</strong> from the page</li>
                                                                <li>Paste here - system auto-extracts data!</li>
                                                            </ol>
                                                            <div className="p-2 bg-gray-100 dark:bg-gray-800 rounded text-xs font-mono mt-2">
                                                                {`{"mesaje": [...], "cnp": "...", ...}`}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </AlertDescription>
                                        </Alert>
                                        
                                        <div>
                                            <Label htmlFor="import-input">ANAF Session Cookies OR Complete Response</Label>
                                            <textarea
                                                id="import-input"
                                                className="w-full h-40 p-3 border rounded-md font-mono text-sm"
                                                placeholder="Paste either:&#10;&#10;1. Cookies: JSESSIONID=ABC123...&#10;   MRHSession=XYZ789...&#10;&#10;2. Complete ANAF Response: {&quot;mesaje&quot;: [...], &quot;cnp&quot;: &quot;...&quot;, ...}"
                                                value={manualJsonInput}
                                                onChange={(e) => setManualJsonInput(e.target.value)}
                                            />
                                        </div>
                                        <div className="flex gap-2">
                                            <Button 
                                                onClick={async () => {
                                                    try {
                                                        setLoading(true)
                                                        const inputText = manualJsonInput.trim()
                                                        
                                                        // Smart detection: Check if input looks like JSON response or cookies
                                                        const looksLikeJson = inputText.startsWith('{') || inputText.includes('"mesaje"') || inputText.includes('"cnp"')
                                                        
                                                        if (looksLikeJson) {
                                                            // Method 2: Smart Response Capture
                                                            setSyncMessage('🔍 Smart capture detected! Extracting ANAF data from response...')
                                                            
                                                            const captureResponse = await fetch('/api/anaf/session/capture', {
                                                                method: 'POST',
                                                                headers: {
                                                                    'Content-Type': 'application/json',
                                                                    'Accept': 'application/json',
                                                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                                                },
                                                                body: JSON.stringify({ response_text: inputText })
                                                            })
                                                            
                                                            const captureResult = await captureResponse.json()
                                                            
                                                            if (captureResult.success && captureResult.data) {
                                                                setSyncMessage('✅ ANAF data extracted! Processing messages...')
                                                                
                                                                // Process the extracted data directly
                                                                const processResponse = await fetch('/spv/process-direct-anaf-data', {
                                                                    method: 'POST',
                                                                    headers: {
                                                                        'Content-Type': 'application/json',
                                                                        'Accept': 'application/json',
                                                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                                                    },
                                                                    body: JSON.stringify({
                                                                        anafData: captureResult.data
                                                                    })
                                                                })
                                                                
                                                                const processResult = await processResponse.json()
                                                                if (processResponse.ok) {
                                                                    setSyncMessage(`✅ Smart capture success! Synced ${processResult.synced_count} messages!`)
                                                                    setTimeout(() => setSyncMessage(null), 5000)
                                                                    setShowManualInput(false)
                                                                    setManualJsonInput('')
                                                                    router.reload({ only: ['messages', 'requests'] })
                                                                } else {
                                                                    setSyncMessage(`❌ Failed to process extracted data: ${processResult.message}`)
                                                                    setTimeout(() => setSyncMessage(null), 8000)
                                                                }
                                                            } else {
                                                                setSyncMessage(`❌ Smart capture failed: ${captureResult.message}`)
                                                                setTimeout(() => setSyncMessage(null), 8000)
                                                            }
                                                        } else {
                                                            // Method 1: Traditional Cookie Import
                                                            setSyncMessage('🔄 Cookie format detected! Importing ANAF session cookies...')
                                                            
                                                            // Parse cookies from text input
                                                            const cookies: Record<string, string> = {}
                                                            const lines = inputText.split('\n')
                                                            
                                                            for (const line of lines) {
                                                                const trimmed = line.trim()
                                                                if (trimmed && trimmed.includes('=')) {
                                                                    const [name, ...valueParts] = trimmed.split('=')
                                                                    const value = valueParts.join('=') // Handle values with = in them
                                                                    if (name && value) {
                                                                        cookies[name.trim()] = value.trim()
                                                                    }
                                                                }
                                                            }
                                                            
                                                            if (Object.keys(cookies).length === 0) {
                                                                setSyncMessage('❌ No valid cookies found. Please check the format or try smart capture method.')
                                                                setTimeout(() => setSyncMessage(null), 8000)
                                                                return
                                                            }
                                                            
                                                            // Import cookies
                                                            const importResponse = await fetch('/api/anaf/session/import', {
                                                                method: 'POST',
                                                                headers: {
                                                                    'Content-Type': 'application/json',
                                                                    'Accept': 'application/json',
                                                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                                                },
                                                                body: JSON.stringify({ cookies })
                                                            })
                                                            
                                                            const importResult = await importResponse.json()
                                                            
                                                            if (importResult.success) {
                                                                setSyncMessage('✅ Session imported! Retrieving messages...')
                                                                
                                                                // Now try to get messages
                                                                const proxyResponse = await fetch('/api/anaf/proxy/listaMesaje?zile=' + syncDays + (syncCif ? '&cif=' + encodeURIComponent(syncCif) : ''))
                                                                const proxyResult = await proxyResponse.json()
                                                                
                                                                if (proxyResult.success && proxyResult.data.mesaje) {
                                                                    // Process the messages
                                                                    const processResponse = await fetch('/spv/process-direct-anaf-data', {
                                                                        method: 'POST',
                                                                        headers: {
                                                                            'Content-Type': 'application/json',
                                                                            'Accept': 'application/json',
                                                                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                                                        },
                                                                        body: JSON.stringify({
                                                                            anafData: proxyResult.data
                                                                        })
                                                                    })
                                                                    
                                                                    const processResult = await processResponse.json()
                                                                    if (processResponse.ok) {
                                                                        setSyncMessage(`✅ Successfully synced ${processResult.synced_count} messages!`)
                                                                        setTimeout(() => setSyncMessage(null), 5000)
                                                                        setShowManualInput(false)
                                                                        setManualJsonInput('')
                                                                        router.reload({ only: ['messages', 'requests'] })
                                                                    } else {
                                                                        setSyncMessage(`❌ Failed to process messages: ${processResult.message}`)
                                                                        setTimeout(() => setSyncMessage(null), 8000)
                                                                    }
                                                                } else {
                                                                    setSyncMessage('❌ Failed to retrieve messages. Session may be expired.')
                                                                    setTimeout(() => setSyncMessage(null), 8000)
                                                                }
                                                            } else {
                                                                setSyncMessage(`❌ Failed to import session: ${importResult.message}`)
                                                                setTimeout(() => setSyncMessage(null), 8000)
                                                            }
                                                        }
                                                        
                                                    } catch (error) {
                                                        console.error('Import error:', error)
                                                        setSyncMessage('❌ Failed to import data. Please check the format and try again.')
                                                        setTimeout(() => setSyncMessage(null), 8000)
                                                    } finally {
                                                        setLoading(false)
                                                    }
                                                }}
                                                disabled={loading || !manualJsonInput.trim()}
                                            >
                                                {loading ? (
                                                    <>
                                                        <Icon iconNode={Loader2} className="mr-2 h-4 w-4 animate-spin" />
                                                        Processing...
                                                    </>
                                                ) : (
                                                    <>
                                                        <Icon iconNode={CheckCircle} className="mr-2 h-4 w-4" />
                                                        Smart Import & Sync
                                                    </>
                                                )}
                                            </Button>
                                            <Button 
                                                variant="outline"
                                                onClick={() => {
                                                    setShowManualInput(false)
                                                    setManualJsonInput('')
                                                }}
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Sync Status Message */}
                            {syncMessage && (
                                <Alert>
                                    <Icon iconNode={Info} className="h-4 w-4" />
                                    <AlertDescription>
                                        <pre className="whitespace-pre-wrap">{syncMessage}</pre>
                                    </AlertDescription>
                                </Alert>
                            )}



                            {/* Messages List */}
                            <Card>
                                <CardHeader>
                                    <CardTitle>Messages ({messages.length})</CardTitle>
                                    <CardDescription>
                                        Latest messages from ANAF SPV
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {messages.length === 0 ? (
                                        <div className="text-center py-8">
                                            <Icon iconNode={Mail} className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                                            <p className="text-muted-foreground">No messages found</p>
                                            <p className="text-sm text-muted-foreground">
                                                Click "Sync Messages" to retrieve messages from ANAF
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="space-y-3">
                                            {messages.map((message) => (
                                                <div key={message.id} className="flex items-center justify-between p-4 border rounded-lg">
                                                    <div className="space-y-1">
                                                        <div className="flex items-center gap-2">
                                                            <Badge variant={getMessageTypeBadge(message.tip)}>
                                                                {message.tip}
                                                            </Badge>
                                                            <span className="text-sm text-muted-foreground">
                                                                CIF: {message.cif}
                                                            </span>
                                                            {message.downloaded_at && (
                                                                <Badge variant="outline" className="text-green-600">
                                                                    <Icon iconNode={Download} className="w-3 h-3 mr-1" />
                                                                    Downloaded
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <div className="font-medium">{message.detalii}</div>
                                                        <div className="text-sm text-muted-foreground">
                                                            Created: {message.formatted_date_creare}
                                                        </div>
                                                    </div>
                                                    <Button
                                                        onClick={() => handleDownload(message.anaf_id)}
                                                        size="sm"
                                                        variant={message.downloaded_at ? "outline" : "default"}
                                                    >
                                                        <Icon iconNode={Download} className="w-4 h-4 mr-2" />
                                                        {message.downloaded_at ? "Re-download" : "Download"}
                                                    </Button>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20 opacity-30 pointer-events-none" />
                </div>
            </div>
        </AppLayout>
    )
}