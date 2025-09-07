import { useState, useEffect } from 'react'
import { Head, router } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
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
    RotateCcw,
    X,
    Wifi,
    WifiOff,
    Zap,
    AlertCircle,
    Eye,
    Check
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
    company_name?: string | null
    company_source?: string | null
    has_file_in_database?: boolean
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

interface SessionStatus {
    active: boolean
    validated: boolean
    expires_at: string | null
    remaining_seconds: number | null
    remaining_minutes: number | null
    expiring_soon: boolean
    cookie_names: string[]
    required_cookies_found: string[]
    required_cookies_missing: string[]
    session_quality: 'excellent' | 'incomplete' | 'insufficient' | 'expired' | 'invalid' | 'unknown'
    source: string
    imported_at: string | null
    authentication_status: string
}

interface SpvIndexProps {
    messages: SpvMessage[]
    requests: SpvRequest[]
    sessionActive: boolean
    sessionExpiry: string | null
    sessionStatus: SessionStatus
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
    {
        title: 'Mesaje',
        href: '/spv',
    },
]

export default function SpvIndex({ 
    messages, 
    requests, 
    sessionActive, 
    sessionExpiry,
    sessionStatus,
    documentTypes,
    incomeReasons,
    apiCallStatus
}: SpvIndexProps) {
    const [loading, setLoading] = useState(false)
    const [syncCif, setSyncCif] = useState('')
    const [tableFilter, setTableFilter] = useState('')
    const [documentTypeFilter, setDocumentTypeFilter] = useState('all')
    const [isTableUpdating, setIsTableUpdating] = useState(false)
    const [syncMessage, setSyncMessage] = useState<string | null>(null)
    const [extensionAvailable, setExtensionAvailable] = useState(false)
    const [extensionLastSync, setExtensionLastSync] = useState<string | null>(null)
    const [extensionStatus, setExtensionStatus] = useState<any>(null)
    const [showExtensionTest, setShowExtensionTest] = useState(false)
    const [testResults, setTestResults] = useState<any>(null)
    const [currentApiStatus, setCurrentApiStatus] = useState<ApiCallStatus | undefined>(apiCallStatus)
    const [connectionStatus, setConnectionStatus] = useState<'checking' | 'connected' | 'disconnected'>(
        sessionActive ? 'connected' : 'disconnected'
    )
    
    // Pagination state
    const [currentPage, setCurrentPage] = useState(1)
    const messagesPerPage = 10

    // Update API call status and connection status when prop changes
    useEffect(() => {
        setCurrentApiStatus(apiCallStatus)
        setConnectionStatus(sessionActive ? 'connected' : 'disconnected')
    }, [apiCallStatus, sessionActive])

    // Function to get session status message based on quality
    const getSessionStatusMessage = () => {
        if (!sessionStatus) return null

        switch (sessionStatus.session_quality) {
            case 'expired':
                return {
                    type: 'warning' as const,
                    title: 'Sesiune ANAF Expirată',
                    message: 'Sesiunea ANAF a expirat. Este necesar să vă reautentificați la ANAF pentru a continua sincronizarea.',
                    action: 'Reautentificare necesară'
                }
            case 'incomplete':
                return {
                    type: 'warning' as const,
                    title: 'Sesiune ANAF Incompletă',
                    message: `Doar ${sessionStatus.required_cookies_found.length}/3 cookie-uri necesare. Lipsă: ${sessionStatus.required_cookies_missing.join(', ')}. Este necesară reautentificarea.`,
                    action: 'Reautentificare necesară - 3 cookie-uri obligatorii'
                }
            case 'insufficient':
                return {
                    type: 'warning' as const,
                    title: 'Sesiune ANAF Insuficientă',
                    message: `Doar ${sessionStatus.required_cookies_found.length}/3 cookie-uri necesare. Este necesară reautentificarea completă.`,
                    action: 'Reautentificare necesară - 3 cookie-uri obligatorii'
                }
            case 'excellent':
                return {
                    type: 'success' as const,
                    title: 'Sesiune ANAF Optimă',
                    message: 'Toate cookie-urile necesare sunt prezente. Sesiunea este complet funcțională.',
                    action: null
                }
            case 'invalid':
                return {
                    type: 'error' as const,
                    title: 'Sesiune ANAF Invalidă',
                    message: 'Nu există cookie-uri valide ANAF. Este necesară autentificarea.',
                    action: 'Autentificare necesară'
                }
            default:
                if (!sessionStatus.active) {
                    return {
                        type: 'warning' as const,
                        title: 'Nicio Sesiune ANAF',
                        message: 'Nu există o conexiune activă cu ANAF. Apăsați "Conectează ANAF" pentru autentificare.',
                        action: 'Autentificare necesară'
                    }
                }
                return null
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

    // Handle connection check/establish
    const handleConnectionCheck = async () => {
        try {
            setConnectionStatus('checking')
            setSyncMessage('🔍 Se verifică conexiunea ANAF...')
            
            // Check session status first
            const sessionResponse = await fetch('/api/anaf/session/status')
            const sessionData = await sessionResponse.json()
            
            if (sessionData.success && sessionData.session?.active) {
                setConnectionStatus('connected')
                setSyncMessage('✅ Conexiune ANAF activă!')
                setTimeout(() => setSyncMessage(null), 3000)
                return
            }
            
            // No active session - try extension or open ANAF
            setConnectionStatus('disconnected')
            
            if (window.anafCookieHelper && extensionAvailable) {
                setSyncMessage('🔌 Se încearcă conectarea prin extensie...')
                
                const cookieResult = await window.anafCookieHelper.getCookies()
                
                if (cookieResult.success && Object.keys(cookieResult.cookies).length > 0) {
                    const syncResult = await window.anafCookieHelper.syncCookies()
                    
                    if (syncResult.success) {
                        setConnectionStatus('connected')
                        setSyncMessage('✅ Conectat prin extensie!')
                        setTimeout(() => setSyncMessage(null), 3000)
                        return
                    }
                }
            }
            
            // Open ANAF for authentication
            setSyncMessage('🔐 Se deschide ANAF pentru autentificare...')
            window.open('https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60', '_blank')
            
            setTimeout(() => setSyncMessage(null), 3000)
            
        } catch (error) {
            console.error('Connection check failed:', error)
            setConnectionStatus('disconnected')
            setSyncMessage('❌ Verificarea conexiunii a eșuat: ' + (error instanceof Error ? error.message : 'Eroare necunoscută'))
            setTimeout(() => setSyncMessage(null), 8000)
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

    // No automatic API calls on page load to preserve API requests
    // API status will be fetched only when sync button is clicked

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
            
            // Extension monitoring without periodic API calls
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

    const handleSyncMessages = async (e?: React.MouseEvent) => {
        console.log('🚨 SYNC BUTTON CLICKED - WITH COOKIE SYNC CHECK', {
            event: e,
            eventType: e?.type,
            eventTarget: e?.target,
            sessionActive,
            connectionStatus
        })
        
        // CRITICAL: Prevent any default behavior (FIXED - removed stopImmediatePropagation)
        if (e) {
            console.log('🛑 Calling preventDefault() and stopPropagation() - FIXED VERSION')
            e.preventDefault()
            e.stopPropagation()
            console.log('✅ preventDefault() and stopPropagation() called successfully - NO ERRORS')
        }
        
        // Use Chrome extension for automatic sync if available
        console.log('🔌 Checking Chrome extension for automatic sync...')
        setSyncMessage('🔄 Se verifică extensia Chrome pentru sincronizare automată...')
        
        // Try extension sync first
        if (window.anafCookieHelper && extensionAvailable) {
            try {
                console.log('🔌 Extension available, attempting sync...')
                setSyncMessage('🔌 Se sincronizează prin extensia Chrome...')
                
                const extensionResult = await window.anafCookieHelper.manualSync()
                
                if (extensionResult.success) {
                    console.log('✅ Extension sync successful!')
                    setSyncMessage('✅ Extensia Chrome a sincronizat cu succes!')
                    setExtensionLastSync(new Date().toISOString())
                } else {
                    console.warn('⚠️ Extension sync failed:', extensionResult.error)
                    setSyncMessage('⚠️ Sincronizarea prin extensie a eșuat. Se încearcă conexiunea directă...')
                }
            } catch (extensionError) {
                console.warn('⚠️ Extension communication failed:', extensionError)
                setSyncMessage('⚠️ Comunicarea cu extensia a eșuat. Se încearcă conexiunea directă...')
            }
        } else {
            console.log('🌐 No extension available, checking direct connection...')
            setSyncMessage('🌐 Nu există extensie disponibilă. Se verifică conexiunea directă...')
        }
        
        console.log('🔄 Starting background AJAX sync process...')
        
        // Check if connection is active before attempting sync
        const isConnected = sessionActive || connectionStatus === 'connected'
        console.log('🔗 Connection check:', { isConnected, sessionActive, connectionStatus })
        
        if (!isConnected) {
            console.log('❌ No connection - aborting sync')
            setSyncMessage('❌ Nu există conexiune activă cu ANAF. Apăsați mai întâi "Conectează ANAF".')
            setTimeout(() => setSyncMessage(null), 5000)
            return
        }
        
        setLoading(true)
        setSyncMessage('🔄 Se sincronizează mesajele în fundal...')
        console.log('📡 Making AJAX request to /spv/sync-messages...')
        
        try {
            // Make pure background AJAX request - ABSOLUTELY NO NAVIGATION
            const response = await fetch('/spv/sync-messages', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    days: 60
                })
            })
            
            console.log('📡 AJAX response received:', {
                status: response.status,
                statusText: response.statusText,
                ok: response.ok,
                headers: Object.fromEntries(response.headers.entries())
            })
            
            const result = await response.json()
            console.log('📊 Parsed response data:', result)
            
            if (response.ok && result.success) {
                console.log('✅ SUCCESS: Sync completed via AJAX!')
                setSyncMessage(`✅ ${result.message || 'Mesaje sincronizate cu succes!'} (${result.synced_count || 0} noi)`)
                
                // Refresh the page data to show new messages
                console.log('🔄 Refreshing page data to show new messages...')
                router.reload({ only: ['messages', 'requests', 'apiCallStatus'] })
                
            } else {
                // Sync failed, try extension fallback
                console.log('❌ Background sync failed, trying extension...')
                setConnectionStatus('disconnected')
                
                if (window.anafCookieHelper && extensionAvailable) {
                    await handleExtensionFallback()
                } else {
                    setSyncMessage('🔐 Autentificare necesară. Se deschide ANAF...')
                    window.open('https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60', '_blank')
                    setTimeout(() => setSyncMessage(null), 3000)
                }
            }
            
        } catch (error) {
            console.error('❌ Background sync AJAX error:', error)
            setSyncMessage('❌ Eroare la sincronizare: ' + (error instanceof Error ? error.message : 'necunoscută'))
        } finally {
            setLoading(false)
            setTimeout(() => setSyncMessage(null), 8000)
            console.log('🏁 Sync process completed - function exiting without navigation')
        }
    }

    // Extension fallback function - separate for clarity
    const handleExtensionFallback = async () => {
        try {
            setSyncMessage('🔌 Încercare prin extensie...')
            
            const cookieResult = await window.anafCookieHelper.getCookies()
            
            if (cookieResult.success && Object.keys(cookieResult.cookies).length > 0) {
                setSyncMessage('✅ Cookie-uri găsite! Se sincronizează...')
                
                const syncResult = await window.anafCookieHelper.syncCookies()
                
                if (syncResult.success) {
                    // Retry with background AJAX after cookie sync
                    const retryResponse = await fetch('/spv/sync-messages', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        },
                        body: JSON.stringify({
                            days: 60
                        })
                    })
                    
                    const retryResult = await retryResponse.json()
                    
                    if (retryResponse.ok && retryResult.success) {
                        setSyncMessage(`✅ Sincronizare reușită prin extensie! (${retryResult.synced_count || 0} noi)`)
                        
                        // Refresh the page data to show new messages
                        console.log('🔄 Refreshing page data after extension sync...')
                        router.reload({ only: ['messages', 'requests', 'apiCallStatus'] })
                        
                        setTimeout(() => setSyncMessage(null), 5000)
                        return
                    }
                }
            }
            
            // Extension failed, open ANAF
            setSyncMessage('🔐 Se deschide ANAF pentru autentificare...')
            window.open('https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60', '_blank')
            setTimeout(() => setSyncMessage(null), 3000)
            
        } catch (error) {
            console.error('Extension fallback failed:', error)
            setSyncMessage('🔐 Se deschide ANAF pentru autentificare...')
            window.open('https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60', '_blank')
            setTimeout(() => setSyncMessage(null), 3000)
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

    const [downloadingStates, setDownloadingStates] = useState<Record<string, 'loading' | 'success' | 'error' | null>>({})

    const handleDownload = async (messageId: string) => {
        setDownloadingStates(prev => ({ ...prev, [messageId]: 'loading' }))
        
        try {
            const response = await fetch(`/spv/download/${messageId}`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })

            if (response.ok) {
                const data = await response.json()
                if (data.success) {
                    setDownloadingStates(prev => ({ ...prev, [messageId]: 'success' }))
                    setTimeout(() => {
                        router.reload({ only: ['messages'] })
                    }, 1000)
                } else {
                    setDownloadingStates(prev => ({ ...prev, [messageId]: 'error' }))
                    setTimeout(() => {
                        setDownloadingStates(prev => ({ ...prev, [messageId]: null }))
                    }, 3000)
                }
            } else {
                setDownloadingStates(prev => ({ ...prev, [messageId]: 'error' }))
                setTimeout(() => {
                    setDownloadingStates(prev => ({ ...prev, [messageId]: null }))
                }, 3000)
            }
        } catch (error) {
            console.error('Download error:', error)
            setDownloadingStates(prev => ({ ...prev, [messageId]: 'error' }))
            setTimeout(() => {
                setDownloadingStates(prev => ({ ...prev, [messageId]: null }))
            }, 3000)
        }
    }

    const handleView = (messageId: string) => {
        window.open(`/spv/viewer/${messageId}`, '_blank')
    }

    const getMessageTypeBadge = (tip: string) => {
        const variants: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
            'RECIPISA': 'default',
            'RAPORT': 'secondary',
            'SOMATIE': 'destructive',
            'SOMATII': 'destructive',
            'SOMATII/TITLURI EXECUTORII': 'destructive',
            'TITLURI EXECUTORII': 'destructive',
            'NOTIFICARE': 'outline',
            'NOTIFICARI': 'outline',
        }
        return variants[tip] || 'secondary'
    }

    // Helper function to get company name from enriched message data
    const getCompanyName = (cif: string): string => {
        // Find the message with this CIF and return its company name
        const message = messages.find(m => m.cif === cif);
        return message?.company_name || `CIF ${cif}`;
    }

    // Helper function to get document type display with proper capitalization
    const getDocumentTypeDisplay = (tip: string): string => {
        const types: Record<string, string> = {
            'RECIPISA': 'Recipisă',
            'RAPORT': 'Răspuns solicitare',
            'NOTIFICARE': 'Notificare',
            'NOTIFICARI': 'Notificări',
            'SOMATIE': 'Somație',
            'SOMATII': 'Somații',
            'SOMATII/TITLURI EXECUTORII': 'Somații/Titluri executorii',
            'TITLURI EXECUTORII': 'Titluri executorii',
            'DECIZIE': 'Decizie',
            'DECIZII': 'Decizii',
            'AVIZ': 'Aviz',
            'AVIZE': 'Avize',
            'CERERE': 'Cerere',
            'CERERI': 'Cereri',
            'SOLICITARE': 'Solicitare',
            'SOLICITARI': 'Solicitări',
            'RASPUNS': 'Răspuns',
            'RASPUNSURI': 'Răspunsuri',
            'FORMULAR': 'Formular',
            'FORMULARE': 'Formulare',
            'DOCUMENT': 'Document',
            'DOCUMENTE': 'Documente',
        }
        
        // If exact match found, return it
        if (types[tip]) {
            return types[tip]
        }
        
        // Otherwise, convert to proper case format (first letter uppercase, rest lowercase)
        return tip.split(' ').map(word => 
            word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
        ).join(' ')
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

    // Get unique document types for dropdown
    const uniqueDocumentTypes = Array.from(new Set(messages.map(message => message.tip)))
        .sort()
        .map(tip => ({
            value: tip,
            label: getDocumentTypeDisplay(tip)
        }))

    // Filter messages based on CIF/company name and document type
    const filteredMessages = messages.filter(message => {
        // CIF/Company name filter
        const cifMatch = tableFilter === '' || (() => {
            const searchTerm = tableFilter.toLowerCase()
            const cif = message.cif.toLowerCase()
            const companyName = getCompanyName(message.cif).toLowerCase()
            return cif.includes(searchTerm) || companyName.includes(searchTerm)
        })()

        // Document type filter
        const typeMatch = documentTypeFilter === '' || documentTypeFilter === 'all' || message.tip === documentTypeFilter

        return cifMatch && typeMatch
    })

    // Pagination calculations
    const totalPages = Math.max(1, Math.ceil(filteredMessages.length / messagesPerPage))
    const startIndex = (currentPage - 1) * messagesPerPage
    const endIndex = startIndex + messagesPerPage
    const paginatedMessages = filteredMessages.slice(startIndex, endIndex)

    // Reset to page 1 when filters change
    useEffect(() => {
        setCurrentPage(1)
    }, [tableFilter, documentTypeFilter])

    // Handle smooth table updates with animation
    useEffect(() => {
        if (tableFilter !== '' || documentTypeFilter !== 'all') {
            setIsTableUpdating(true)
            const timer = setTimeout(() => setIsTableUpdating(false), 150)
            return () => clearTimeout(timer)
        }
    }, [tableFilter, documentTypeFilter])

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SPV - Spațiul Privat Virtual ANAF" />
            
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
                {/* Main Content Area */}
                <div className="relative min-h-[60vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    {/* Connection Status and Controls */}
                    <div className="mb-6 p-6 pb-0">
                        <div className="flex items-center justify-between mb-4">
                            <h2 className="text-lg font-semibold">
                                Mesaje ANAF ({filteredMessages.length}{messages.length !== filteredMessages.length ? ` din ${messages.length}` : ''})
                                {totalPages > 1 && (
                                    <span className="text-sm font-normal text-muted-foreground ml-2">
                                        - Pagina {currentPage} din {totalPages}
                                    </span>
                                )}
                            </h2>
                            
                            {/* Control Area */}
                            <div className="flex items-center gap-3">                                
                                {/* Sync Messages Button - FIXED! */}
                                <Button
                                    type="button"
                                    onClick={handleSyncMessages}
                                    disabled={loading || (!sessionActive && connectionStatus !== 'connected')}
                                    size="default"
                                    className={`transition-colors duration-200 w-[200px] h-10 ${
                                        (!sessionActive && connectionStatus !== 'connected') ? 'opacity-50 cursor-not-allowed' : ''
                                    }`}
                                >
                                    <div className="flex items-center justify-center gap-2 w-full h-full">
                                        <Icon 
                                            iconNode={RefreshCw} 
                                            className={`h-4 w-4 flex-shrink-0 ${loading ? 'animate-spin' : ''}`} 
                                        />
                                        <span className="text-sm font-medium truncate">
                                            {loading ? 'Se sincronizează...' : 'Sincronizare mesaje'}
                                        </span>
                                        {!loading && (!sessionActive && connectionStatus !== 'connected') && (
                                            <Icon iconNode={AlertCircle} className="h-3 w-3 text-orange-500 flex-shrink-0" />
                                        )}
                                    </div>
                                </Button>
                            </div>
                        </div>
                        
                        {/* Enhanced Session Status Alert */}
                        {(() => {
                            const statusMessage = getSessionStatusMessage()
                            if (!statusMessage) return null

                            const alertColors = {
                                error: 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950',
                                warning: 'border-orange-200 bg-orange-50 dark:border-orange-800 dark:bg-orange-950',
                                info: 'border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950',
                                success: 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950'
                            }

                            const textColors = {
                                error: 'text-red-700 dark:text-red-300',
                                warning: 'text-orange-700 dark:text-orange-300',
                                info: 'text-blue-700 dark:text-blue-300',
                                success: 'text-green-700 dark:text-green-300'
                            }

                            const iconColors = {
                                error: 'text-red-600',
                                warning: 'text-orange-600',
                                info: 'text-blue-600',
                                success: 'text-green-600'
                            }

                            return (
                                <Alert className={`mb-4 ${alertColors[statusMessage.type]}`}>
                                    <AlertCircle className={`h-4 w-4 ${iconColors[statusMessage.type]}`} />
                                    <AlertDescription className={textColors[statusMessage.type]}>
                                        <strong>{statusMessage.title}:</strong> {statusMessage.message}
                                        {statusMessage.action && (
                                            <div className="mt-2 text-sm opacity-90">
                                                📍 {statusMessage.action}
                                            </div>
                                        )}
                                        {sessionStatus && sessionStatus.session_quality === 'expired' && (
                                            <div className="mt-2 text-xs opacity-75">
                                                Cookie-uri disponibile: {sessionStatus.cookie_names.join(', ')}
                                            </div>
                                        )}
                                    </AlertDescription>
                                </Alert>
                            )
                        })()}
                        
                        {/* Cookie Extraction Help */}
                        {!sessionActive && (
                            <Alert className="mb-4 border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                                <Icon iconNode={RefreshCw} className="h-4 w-4 text-blue-600" />
                                <AlertDescription className="text-blue-700 dark:text-blue-300">
                                    <strong>Pentru sincronizare automată:</strong><br />
                                    1. Autentificați-vă la ANAF într-un browser<br />
                                    2. Închideți browserul complet<br />
                                    3. Apăsați "Sincronizare mesaje" - sistemul va extrage automat cookie-urile
                                </AlertDescription>
                            </Alert>
                        )}
                        
                    </div>
                    
                    <>
                        {messages.length === 0 ? (
                            <div className="text-center py-12 px-6">
                                <Icon iconNode={Mail} className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                                <p className="text-muted-foreground font-medium">Nu au fost găsite mesaje</p>
                                <p className="text-sm text-muted-foreground mt-2">
                                    Apăsați "Sincronizare mesaje ANAF" pentru a prelua mesajele de la ANAF
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div className="overflow-x-auto">
                                            <table className="w-full table-fixed">
                                                <thead className="border-b bg-muted/50">
                                                    <tr>
                                                        <th className="text-left p-4 font-semibold text-sm w-[160px]">
                                                            <div className="flex items-center gap-2">
                                                                <span className="whitespace-nowrap">CIF</span>
                                                                <Input
                                                                    type="text"
                                                                    placeholder="Caută CIF..."
                                                                    value={tableFilter}
                                                                    onChange={(e) => setTableFilter(e.target.value)}
                                                                    className="flex-1 text-xs h-8 bg-background/50 border-muted-foreground/30 focus:border-primary focus:bg-background transition-colors"
                                                                />
                                                            </div>
                                                        </th>
                                                        <th className="text-left p-4 font-semibold text-sm w-[220px]">
                                                            <div className="flex items-center gap-2">
                                                                <span className="whitespace-nowrap">Tip document</span>
                                                                <Select value={documentTypeFilter} onValueChange={setDocumentTypeFilter}>
                                                                    <SelectTrigger className="h-8 text-xs bg-background/50 border-muted-foreground/30 focus:border-primary transition-colors">
                                                                        <SelectValue placeholder="Toate" />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="all">Toate tipurile</SelectItem>
                                                                        {uniqueDocumentTypes.map((type) => (
                                                                            <SelectItem key={type.value} value={type.value}>
                                                                                {type.label}
                                                                            </SelectItem>
                                                                        ))}
                                                                    </SelectContent>
                                                                </Select>
                                                            </div>
                                                        </th>
                                                        <th className="text-left p-4 font-semibold text-sm w-[150px]">Data afișare</th>
                                                        <th className="text-left p-4 font-semibold text-sm w-[300px]">Detalii</th>
                                                        <th className="text-left p-4 font-semibold text-sm w-[90px]">Descarcă</th>
                                                    </tr>
                                                </thead>
                                                <tbody className={`transition-opacity duration-200 ease-in-out ${isTableUpdating ? 'opacity-50' : 'opacity-100'}`}>
                                                    {paginatedMessages.length === 0 ? (
                                                        <tr className="border-b bg-muted/5">
                                                            <td colSpan={5} className="p-4 text-center">
                                                                <div className="text-sm leading-relaxed h-12 overflow-hidden flex items-center justify-center">
                                                                    <div className="text-muted-foreground font-medium">Nu au fost găsite mesaje pentru criteriile selectate</div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    ) : (
                                                        paginatedMessages.map((message, index) => (
                                                            <tr 
                                                                key={message.id} 
                                                                className={`border-b hover:bg-muted/30 transition-colors duration-200 ease-in-out ${index % 2 === 0 ? 'bg-background' : 'bg-muted/10'} ${message.downloaded_at && message.has_file_in_database ? 'border-r-4 border-r-green-500' : ''}`}
                                                            >
                                                            <td className="p-4 align-top w-[160px]">
                                                                <div className="space-y-1">
                                                                    <div className="font-semibold text-sm truncate">{message.cif}</div>
                                                                    <div className="text-xs text-muted-foreground truncate">
                                                                        {getCompanyName(message.cif)}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[220px]">
                                                                <Badge variant={getMessageTypeBadge(message.tip)} className="whitespace-nowrap">
                                                                    {getDocumentTypeDisplay(message.tip)}
                                                                </Badge>
                                                            </td>
                                                            <td className="p-4 align-top w-[150px]">
                                                                <div className="text-sm font-medium">
                                                                    {formatRomanianDate(message.data_creare || message.formatted_date_creare || '')}
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[300px]">
                                                                <div className="text-sm leading-relaxed h-12 overflow-hidden">
                                                                    <div className="line-clamp-3">{message.detalii}</div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[90px]">
                                                                {message.downloaded_at && message.has_file_in_database ? (
                                                                    <Button
                                                                        onClick={() => handleView(message.anaf_id)}
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="w-full text-xs px-1"
                                                                    >
                                                                        <Icon iconNode={Eye} className="w-3 h-3 mr-1" />
                                                                        Vizualizează
                                                                    </Button>
                                                                ) : (
                                                                    <Button
                                                                        onClick={() => handleDownload(message.anaf_id)}
                                                                        size="sm"
                                                                        variant="outline"
                                                                        disabled={downloadingStates[message.anaf_id] === 'loading'}
                                                                        className={`w-full text-xs px-1 transition-colors ${
                                                                            downloadingStates[message.anaf_id] === 'success' ? 'bg-green-500 text-white border-green-500' :
                                                                            downloadingStates[message.anaf_id] === 'error' ? 'bg-red-500 text-white border-red-500' :
                                                                            ''
                                                                        }`}
                                                                    >
                                                                        {downloadingStates[message.anaf_id] === 'loading' && (
                                                                            <Icon iconNode={Loader2} className="w-3 h-3 mr-1 animate-spin" />
                                                                        )}
                                                                        {downloadingStates[message.anaf_id] === 'success' && (
                                                                            <Icon iconNode={Check} className="w-3 h-3 mr-1" />
                                                                        )}
                                                                        {downloadingStates[message.anaf_id] === 'error' && (
                                                                            <Icon iconNode={X} className="w-3 h-3 mr-1" />
                                                                        )}
                                                                        {!downloadingStates[message.anaf_id] && (
                                                                            <Icon iconNode={Download} className="w-3 h-3 mr-1" />
                                                                        )}
                                                                        {downloadingStates[message.anaf_id] === 'loading' ? 'Se preia...' :
                                                                         downloadingStates[message.anaf_id] === 'success' ? 'Gata!' :
                                                                         downloadingStates[message.anaf_id] === 'error' ? 'Eroare' :
                                                                         'Preia'}
                                                                    </Button>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))
                                                    )}
                                                    
                                                    {/* Empty placeholder rows to maintain consistent table height */}
                                                    {Array.from({ length: messagesPerPage - paginatedMessages.length - (paginatedMessages.length === 0 ? 1 : 0) }, (_, i) => (
                                                        <tr key={`empty-${i}`} className={`border-b ${(paginatedMessages.length + i) % 2 === 0 ? 'bg-background' : 'bg-muted/10'}`}>
                                                            <td className="p-4 align-top w-[160px]">
                                                                <div className="space-y-1">
                                                                    <div className="font-semibold text-sm truncate">&nbsp;</div>
                                                                    <div className="text-xs text-muted-foreground truncate">&nbsp;</div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[220px]">
                                                                <div className="whitespace-nowrap">&nbsp;</div>
                                                            </td>
                                                            <td className="p-4 align-top w-[150px]">
                                                                <div className="text-sm font-medium">&nbsp;</div>
                                                            </td>
                                                            <td className="p-4 align-top w-[300px]">
                                                                <div className="text-sm leading-relaxed h-12 overflow-hidden">
                                                                    <div className="line-clamp-3">&nbsp;</div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[90px]">
                                                                <div className="whitespace-nowrap">&nbsp;</div>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                            </div>
                            
                            {/* Pagination Controls */}
                            {(
                                <div className="flex items-center justify-between border-t bg-muted/20 px-6 py-3 rounded-b-lg">
                                    <div className="flex items-center text-sm text-muted-foreground">
                                        <span>
                                            Afișate {startIndex + 1}-{Math.min(endIndex, filteredMessages.length)} din {filteredMessages.length} mesaje
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setCurrentPage(currentPage - 1)}
                                            disabled={currentPage === 1}
                                            className="h-8 w-8 p-0"
                                        >
                                            <Icon iconNode={RotateCcw} className="h-4 w-4 rotate-90" />
                                        </Button>
                                        
                                        <div className="flex items-center gap-1">
                                            {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => {
                                                if (
                                                    page === 1 ||
                                                    page === totalPages ||
                                                    (page >= currentPage - 1 && page <= currentPage + 1)
                                                ) {
                                                    return (
                                                        <Button
                                                            key={page}
                                                            variant={page === currentPage ? "default" : "outline"}
                                                            size="sm"
                                                            onClick={() => setCurrentPage(page)}
                                                            className="h-8 w-8 p-0 text-xs"
                                                        >
                                                            {page}
                                                        </Button>
                                                    )
                                                } else if (
                                                    page === currentPage - 2 ||
                                                    page === currentPage + 2
                                                ) {
                                                    return (
                                                        <span key={page} className="text-muted-foreground px-1">
                                                            ...
                                                        </span>
                                                    )
                                                }
                                                return null
                                            })}
                                        </div>
                                        
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setCurrentPage(currentPage + 1)}
                                            disabled={currentPage === totalPages}
                                            className="h-8 w-8 p-0"
                                        >
                                            <Icon iconNode={RotateCcw} className="h-4 w-4 -rotate-90" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </div>
                        )}
                        
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20 opacity-30 pointer-events-none" />
                    </>
                </div>
            </div>
        </AppLayout>
    )
}
