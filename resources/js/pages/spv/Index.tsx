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
    Loader2,
    Trash2,
    RotateCcw
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

interface ApiCallStatus {
    calls_made: number
    calls_limit: number
    calls_remaining: number
    reset_at: string | null
    recent_errors: Array<{
        error: string
        timestamp: string
        call_number: number
    }>
}

interface SpvIndexProps {
    messages: SpvMessage[]
    requests: SpvRequest[]
    sessionActive: boolean
    sessionExpiry: string | null
    documentTypes: Record<string, string>
    incomeReasons: string[]
    apiCallStatus?: ApiCallStatus
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
]

export default function SpvIndex({ 
    messages, 
    requests, 
    sessionActive, 
    sessionExpiry,
    documentTypes,
    incomeReasons,
    apiCallStatus
}: SpvIndexProps) {
    const [loading, setLoading] = useState(false)
    const [syncCif, setSyncCif] = useState('')
    const [autoSynced, setAutoSynced] = useState(false)
    const [syncMessage, setSyncMessage] = useState<string | null>(null)
    const [extensionAvailable, setExtensionAvailable] = useState(false)
    const [extensionLastSync, setExtensionLastSync] = useState<string | null>(null)
    const [extensionStatus, setExtensionStatus] = useState<any>(null)
    const [showExtensionTest, setShowExtensionTest] = useState(false)
    const [testResults, setTestResults] = useState<any>(null)
    const [currentApiStatus, setCurrentApiStatus] = useState<ApiCallStatus | undefined>(apiCallStatus)

    // Function to fetch updated API call status
    const fetchApiCallStatus = async () => {
        try {
            const response = await fetch('/spv/api-call-status', {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                }
            })
            
            if (response.ok) {
                const result = await response.json()
                if (result.success) {
                    setCurrentApiStatus(result.data)
                }
            }
        } catch (error) {
            console.error('Failed to fetch API call status:', error)
        }
    }

    // Function to reset API call counter
    const handleResetApiCounter = async () => {
        try {
            const response = await fetch('/spv/reset-api-counter', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                }
            })
            
            if (response.ok) {
                const result = await response.json()
                if (result.success) {
                    setCurrentApiStatus(result.data)
                    setSyncMessage('✅ Contorul API a fost resetat cu succes!')
                }
            }
        } catch (error) {
            console.error('Failed to reset API counter:', error)
            setSyncMessage('❌ Eroare la resetarea contorului API')
        }
    }

    // Function to check and update extension availability
    const checkExtensionAvailability = () => {
        const isAvailable = !!window.anafCookieHelper
        console.log('🔍 Extension availability check:', isAvailable)
        
        if (isAvailable !== extensionAvailable) {
            setExtensionAvailable(isAvailable)
            console.log('📱 Updated extension availability state:', isAvailable)
        }
        
        if (isAvailable && window.anafCookieHelper) {
            try {
                const status = window.anafCookieHelper.getStatus()
                setExtensionStatus(status)
                console.log('📊 Updated extension status:', status)
            } catch (error) {
                console.error('❌ Failed to get extension status:', error)
            }
        }
        
        return isAvailable
    }

    // Auto-sync on page load if session is active and no messages exist
    useEffect(() => {
        if (sessionActive && messages.length === 0 && !autoSynced) {
            console.log('Auto-syncing messages on page load...')
            handleSyncMessages()
            setAutoSynced(true)
        }
        
        // Also fetch initial API call status
        fetchApiCallStatus()
    }, [sessionActive, messages.length, autoSynced])

    // Extension detection and monitoring
    useEffect(() => {
        const detectExtension = () => {
            // Check for extension API directly
            if (window.anafCookieHelper) {
                console.log('🔌 ANAF Cookie Helper extension API detected!')
                setExtensionAvailable(true)
                setExtensionLastSync(new Date().toISOString())
                
                // Get extension status
                try {
                    const status = window.anafCookieHelper.getStatus()
                    setExtensionStatus(status)
                    console.log('📊 Extension status:', status)
                } catch (error) {
                    console.error('❌ Failed to get extension status:', error)
                }
                return
            }
            
            // Listen for extension load event
            const handleExtensionLoaded = (event: any) => {
                console.log('🔌 ANAF Cookie Helper extension loaded!', event.detail)
                setExtensionAvailable(true)
                setExtensionLastSync(event.detail?.timestamp || new Date().toISOString())
                
                // Get extension status after load
                if (window.anafCookieHelper) {
                    try {
                        const status = window.anafCookieHelper.getStatus()
                        setExtensionStatus(status)
                    } catch (error) {
                        console.error('❌ Failed to get extension status on load:', error)
                    }
                }
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
                    console.log('🔌 Extension API found on delayed check')
                    setExtensionAvailable(true)
                    setExtensionLastSync(new Date().toISOString())
                    
                    // Get extension status
                    try {
                        const status = window.anafCookieHelper.getStatus()
                        setExtensionStatus(status)
                        console.log('📊 Extension status (delayed):', status)
                    } catch (error) {
                        console.error('❌ Failed to get extension status (delayed):', error)
                    }
                }
            }, 1500)
            
            // Additional check after extension events
            setTimeout(() => {
                if (window.anafCookieHelper) {
                    console.log('🔌 Final extension check - API available:', !!window.anafCookieHelper)
                    setExtensionAvailable(true)
                }
            }, 3000)
            
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
            
            // Also periodically update API call status
            fetchApiCallStatus()
        }, 10000) // Check every 10 seconds

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

    const handleClearData = async () => {
        if (!confirm('Sunteți sigur că doriți să ștergeți toate mesajele și cererile SPV? Această acțiune nu poate fi anulată.')) {
            return
        }
        
        try {
            setLoading(true)
            setSyncMessage('🗑️ Se șterg datele SPV...')
            
            const response = await fetch('/spv/clear-data', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
            })
            
            const data = await response.json()
            
            if (data.success) {
                setSyncMessage(`✅ ${data.message}`)
                
                // Refresh the page to show cleared data
                setTimeout(() => {
                    router.reload()
                }, 1500)
            } else {
                setSyncMessage(`❌ Eroare la ștergerea datelor: ${data.message}`)
            }
            
        } catch (error) {
            console.error('Clear data failed:', error)
            setSyncMessage('❌ Eroare la ștergerea datelor. Verificați consola pentru detalii.')
        } finally {
            setLoading(false)
            setTimeout(() => setSyncMessage(null), 5000)
        }
    }

    const handleSyncMessages = async () => {
        console.log('Sync button clicked!')
        
        try {
            setLoading(true)
            setSyncMessage('🔄 Se verifică sesiunea ANAF activă...')
            
            // Step 1: Always try backend sync first (extension may have auto-synced cookies)
            console.log('📡 Trying backend sync with existing session...')
            
            const response = await fetch('/spv/sync-messages', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    days: 60,
                    cif: syncCif || undefined
                })
            })
            
            const result = await response.json()
            
            if (response.ok && result.success) {
                setSyncMessage(`✅ Succes! ${result.synced_count} mesaje noi sincronizate (${result.total_messages} mesaje găsite în total)`)
                setTimeout(() => setSyncMessage(null), 5000)
                router.reload({ only: ['messages', 'requests'] })
                // Update API call status after successful sync
                await fetchApiCallStatus()
                return
            }
            
            // Step 2: If backend sync failed and extension is available, try extension cookie sync
            if (window.anafCookieHelper && extensionAvailable) {
                console.log('🔌 Backend sync failed, trying extension cookie sync...')
                setSyncMessage('🔌 Extensie detectată! Se preiau cookie-urile ANAF...')
                
                const cookieResult = await window.anafCookieHelper.getCookies()
                
                if (cookieResult.success && Object.keys(cookieResult.cookies).length > 0) {
                    console.log('✅ Extension: Found ANAF cookies, syncing to backend...')
                    setSyncMessage('✅ Cookie-uri găsite! Se sincronizează cu backend-ul...')
                    
                    // Sync cookies using extension
                    const syncResult = await window.anafCookieHelper.syncCookies()
                    
                    if (syncResult.success) {
                        setSyncMessage('✅ Cookie-uri sincronizate! Se reîncearcă sincronizarea mesajelor...')
                        
                        // Retry backend sync with fresh cookies
                        const retryResponse = await fetch('/spv/sync-messages', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                            },
                            body: JSON.stringify({
                                days: 60,
                                cif: syncCif || undefined
                            })
                        })
                        
                        const retryResult = await retryResponse.json()
                        
                        if (retryResponse.ok && retryResult.success) {
                            setSyncMessage(`✅ Succes! ${retryResult.synced_count} mesaje noi sincronizate (${retryResult.total_messages} mesaje găsite în total)`)
                            setTimeout(() => setSyncMessage(null), 5000)
                            router.reload({ only: ['messages', 'requests'] })
                            // Update API call status after successful sync
                            await fetchApiCallStatus()
                            return
                        }
                    }
                }
                
                setSyncMessage('🔐 Cookie-urile extensiei au expirat sau sunt invalide. Se deschide ANAF pentru autentificare...')
            } else {
                setSyncMessage('🔐 Autentificare necesară. Se deschide ANAF pentru autentificare...')
            }
            
            // Open ANAF for authentication - extension will auto-capture cookies
            window.open('https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60', '_blank')
            
            setSyncMessage(`🔐 Vă rugăm să vă autentificați la ANAF în tab-ul nou.

${window.anafCookieHelper ? 'Extensia va face automat:' : 'După autentificare:'}
1. ${window.anafCookieHelper ? 'Capturarea cookie-urilor de autentificare' : 'Folosiți extensia pentru a captura cookie-urile'}
2. ${window.anafCookieHelper ? 'Sincronizarea lor cu această aplicație' : 'Sau importați manual datele de sesiune'}
3. ${window.anafCookieHelper ? 'Activarea preluării automate a mesajelor' : 'Apoi sincronizați mesajele'}

După autentificare, apăsați din nou "Sincronizare mesaje ANAF".`)
            
            setTimeout(() => setSyncMessage(null), 15000)
            
        } catch (error) {
            console.error('Sync failed:', error)
            setSyncMessage('❌ Sincronizarea a eșuat: ' + (error instanceof Error ? error.message : 'Eroare necunoscută'))
            setTimeout(() => setSyncMessage(null), 8000)
        } finally {
            setLoading(false)
        }
    }

    // Manual extension test functions
    const handleTestConnection = async () => {
        // Debug: Log current state
        console.log('🔍 Debug - Extension API check:', {
            windowAnafCookieHelper: !!window.anafCookieHelper,
            extensionAvailableState: extensionAvailable,
            windowKeys: window.anafCookieHelper ? Object.keys(window.anafCookieHelper) : 'not found'
        })
        
        // Check for extension API directly (don't rely on state)
        if (!window.anafCookieHelper) {
            console.log('❌ Extension API not found at test time')
            setTestResults({ 
                success: false, 
                error: 'API-ul extensiei nu a fost găsit. Extensia se poate încărca - încercați să reîmprospătați pagina și să așteptați câteva secunde.',
                timestamp: new Date().toISOString()
            })
            return
        }

        try {
            console.log('🧪 Testing extension connection...')
            console.log('🔍 Extension API available:', !!window.anafCookieHelper)
            console.log('🔍 Extension version:', window.anafCookieHelper?.version)
            
            const result = await window.anafCookieHelper.testConnection()
            setTestResults({
                ...result,
                timestamp: new Date().toISOString()
            })
            console.log('🧪 Test result:', result)
        } catch (error) {
            console.error('🧪 Test failed:', error)
            setTestResults({
                success: false,
                error: error instanceof Error ? error.message : 'Unknown error',
                timestamp: new Date().toISOString()
            })
        }
    }

    const handleManualSync = async () => {
        // Check for extension API directly (don't rely on state)
        if (!window.anafCookieHelper) {
            setSyncMessage('❌ API-ul extensiei nu a fost găsit. Vă rugăm să vă asigurați că extensia ANAF Cookie Helper este instalată și activată.')
            setTimeout(() => setSyncMessage(null), 8000)
            return
        }

        try {
            setLoading(true)
            setSyncMessage('🔄 Sincronizare manuală prin extensie...')
            
            const result = await window.anafCookieHelper.manualSync()
            
            if (result.success) {
                setSyncMessage(`✅ Sincronizare manuală reușită! ${result.cookieCount || 0} cookie-uri sincronizate`)
                setExtensionLastSync(new Date().toISOString())
                
                // Update extension status
                try {
                    const status = window.anafCookieHelper.getStatus()
                    setExtensionStatus(status)
                } catch (error) {
                    console.error('Failed to update status after sync:', error)
                }
            } else {
                setSyncMessage(`❌ Sincronizarea manuală a eșuat: ${result.error}`)
            }
            
            setTimeout(() => setSyncMessage(null), 8000)
        } catch (error) {
            console.error('Manual sync error:', error)
            setSyncMessage('❌ Eroare la sincronizarea manuală: ' + (error instanceof Error ? error.message : 'Eroare necunoscută'))
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

    // Helper function to get mock company name based on CIF
    const getCompanyName = (cif: string): string => {
        const mockCompanies: Record<string, string> = {
            '12345678': 'SC EXEMPLU SRL',
            '87654321': 'COMPANIA DEMO SA',
            '11223344': 'FIRMA TEST SRL',
            '44332211': 'SOCIETATEA COMERCIALA SRL',
            '99887766': 'INTREPRINDEREA PUBLICA SA',
        }
        return mockCompanies[cif] || `SC ${cif.substring(0, 4)} SRL`
    }

    // Helper function to get document type display
    const getDocumentTypeDisplay = (tip: string): string => {
        const types: Record<string, string> = {
            'RECIPISA': 'Recipisă',
            'RAPORT': 'Răspuns solicitare',
            'NOTIFICARE': 'Notificare',
            'SOMATIE': 'Somație',
        }
        return types[tip] || tip
    }

    // Helper function to format date in Romanian format
    const formatRomanianDate = (dateString: string): string => {
        try {
            const date = new Date(dateString)
            if (isNaN(date.getTime())) {
                return dateString // Return original if invalid date
            }
            
            const day = date.getDate().toString().padStart(2, '0')
            const month = (date.getMonth() + 1).toString().padStart(2, '0')
            const year = date.getFullYear()
            const hours = date.getHours().toString().padStart(2, '0')
            const minutes = date.getMinutes().toString().padStart(2, '0')
            const seconds = date.getSeconds().toString().padStart(2, '0')
            
            return `${day}.${month}.${year} ${hours}:${minutes}:${seconds}`
        } catch (error) {
            return dateString // Return original if formatting fails
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SPV - Spațiul Privat Virtual ANAF" />
            
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
                {/* Bara de status sincronizare ANAF */}
                <div className="flex items-center justify-between p-4 rounded-lg border bg-card">
                    <div className="flex items-center gap-3">
                        <div className="flex items-center gap-2">
                            <div className={`w-2 h-2 rounded-full ${sessionActive ? 'bg-green-500' : 'bg-red-500'}`} />
                            <span className="text-sm font-medium">
                                {sessionActive ? 'Conectat la ANAF' : 'Deconectat de la ANAF'}
                            </span>
                        </div>
                        {extensionLastSync && (
                            <span className="text-xs text-muted-foreground">
                                Ultima sincronizare: {new Date(extensionLastSync).toLocaleString('ro-RO')}
                            </span>
                        )}
                    </div>
                    
                    <div className="flex items-center gap-3">
                        <div className="flex items-center gap-2">
                            <Input
                                type="text"
                                placeholder="Filtru CIF (opțional)"
                                value={syncCif}
                                onChange={(e) => setSyncCif(e.target.value)}
                                className="w-40"
                                disabled={loading}
                            />
                        </div>
                        
                        <Button
                            onClick={handleSyncMessages}
                            disabled={loading}
                            size="default"
                            className={`relative ${sessionActive ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'}`}
                        >
                            {loading ? (
                                <>
                                    <Icon iconNode={Loader2} className="mr-2 h-4 w-4 animate-spin" />
                                    Se sincronizează...
                                </>
                            ) : (
                                <div className="flex items-center gap-2">
                                    <Icon iconNode={RefreshCw} className="h-4 w-4" />
                                    <span>Sincronizare mesaje ANAF</span>
                                    {currentApiStatus && (
                                        <Badge 
                                            variant="secondary" 
                                            className={`ml-2 text-xs ${
                                                currentApiStatus.calls_remaining > 20 
                                                    ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' 
                                                    : currentApiStatus.calls_remaining > 5 
                                                    ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' 
                                                    : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                            }`}
                                        >
                                            {currentApiStatus.calls_remaining}/{currentApiStatus.calls_limit}
                                        </Badge>
                                    )}
                                </div>
                            )}
                        </Button>
                        
                        {/* Buton resetare contor API - afișează când apeluri > 80 sau utilizatorul are erori */}
                        {currentApiStatus && (currentApiStatus.calls_made > 80 || currentApiStatus.recent_errors.length > 0) && (
                            <Button 
                                onClick={handleResetApiCounter}
                                disabled={loading}
                                size="default"
                                variant="outline"
                                className="border-yellow-200 text-yellow-600 hover:bg-yellow-50 hover:text-yellow-700"
                                title={`Resetare contor API (${currentApiStatus.calls_made}/${currentApiStatus.calls_limit} folosite)`}
                            >
                                <Icon iconNode={RotateCcw} className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </div>

                {/* Main Content Area */}
                <div className="relative min-h-[60vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <div className="absolute inset-0 p-6 overflow-y-auto">
                        <div className="space-y-6">
                            {/* Lista mesaje ANAF */}
                            <Card>
                                <CardHeader>
                                    <CardTitle>Mesaje ANAF ({messages.length})</CardTitle>
                                    <CardDescription>
                                        Ultimele mesaje din Spațiul Privat Virtual ANAF
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="p-0">
                                    {messages.length === 0 ? (
                                        <div className="text-center py-12 px-6">
                                            <Icon iconNode={Mail} className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                                            <p className="text-muted-foreground font-medium">Nu au fost găsite mesaje</p>
                                            <p className="text-sm text-muted-foreground mt-2">
                                                Apăsați "Sincronizare mesaje ANAF" pentru a prelua mesajele de la ANAF
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full">
                                                <thead className="border-b bg-muted/50">
                                                    <tr>
                                                        <th className="text-left p-4 font-semibold text-sm">CIF</th>
                                                        <th className="text-left p-4 font-semibold text-sm">Tip document</th>
                                                        <th className="text-left p-4 font-semibold text-sm">Data afișare</th>
                                                        <th className="text-left p-4 font-semibold text-sm">Detalii</th>
                                                        <th className="text-left p-4 font-semibold text-sm">Descarcă document</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {messages.map((message, index) => (
                                                        <tr key={message.id} className={`border-b hover:bg-muted/30 ${index % 2 === 0 ? 'bg-background' : 'bg-muted/10'}`}>
                                                            <td className="p-4 align-top">
                                                                <div className="space-y-1">
                                                                    <div className="font-semibold text-sm">{message.cif}</div>
                                                                    <div className="text-xs text-muted-foreground">
                                                                        {getCompanyName(message.cif)}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top">
                                                                <Badge variant={getMessageTypeBadge(message.tip)} className="whitespace-nowrap">
                                                                    {getDocumentTypeDisplay(message.tip)}
                                                                </Badge>
                                                            </td>
                                                            <td className="p-4 align-top min-w-[140px]">
                                                                <div className="text-sm font-medium">
                                                                    {formatRomanianDate(message.data_creare || message.formatted_date_creare || '')}
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top max-w-xs">
                                                                <div className="text-sm leading-relaxed">
                                                                    {message.detalii}
                                                                </div>
                                                                {message.downloaded_at && (
                                                                    <Badge variant="outline" className="text-green-600 mt-2">
                                                                        <Icon iconNode={Download} className="w-3 h-3 mr-1" />
                                                                        Descărcat
                                                                    </Badge>
                                                                )}
                                                            </td>
                                                            <td className="p-4 align-top">
                                                                <Button
                                                                    onClick={() => handleDownload(message.anaf_id)}
                                                                    size="sm"
                                                                    variant={message.downloaded_at ? "outline" : "default"}
                                                                    className="whitespace-nowrap"
                                                                >
                                                                    <Icon iconNode={Download} className="w-4 h-4 mr-2" />
                                                                    {message.downloaded_at ? "Descarcă din nou" : "Descarcă"}
                                                                </Button>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
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
