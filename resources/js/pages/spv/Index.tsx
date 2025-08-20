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
    AlertCircle
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
        
        // FIRST: Try Python cookie scraper, if it fails, use browser-based extraction
        console.log('🐍 Running Python cookie scraper (scrap.py)...')
        setSyncMessage('🔄 Se extrag cookie-urile din browser...')
        
        let scraperSuccess = false
        
        try {
            const scraperResponse = await fetch('/api/anaf/run-cookie-scraper', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                }
            })
            
            const scraperResult = await scraperResponse.json()
            console.log('🐍 Python scraper result:', scraperResult)
            
            if (scraperResult.success) {
                console.log('✅ Cookie scraping successful!')
                setSyncMessage('✅ Cookie-uri extrase cu succes!')
                scraperSuccess = true
            } else {
                console.warn('⚠️ Python scraper failed, trying browser method:', scraperResult)
                
                // If Chrome is running or no cookies found, use browser-based extraction
                if (scraperResult.output && (scraperResult.output.includes('database locked') || scraperResult.output.includes('No ANAF cookies found'))) {
                    console.log('🌐 Switching to browser-based cookie extraction...')
                    setSyncMessage('🌐 Se deschide ANAF pentru extragerea cookie-urilor...')
                    
                    // Open ANAF cookie helper
                    const helperWindow = window.open(
                        '/anaf/cookie-helper', 
                        '_blank',
                        'width=800,height=700,scrollbars=yes'
                    )
                    
                    if (helperWindow) {
                        setSyncMessage('🔐 Urmați instrucțiunile din fereastra deschisă pentru autentificare')
                        setTimeout(() => setSyncMessage(null), 8000)
                        setLoading(false)
                        return
                    } else {
                        setSyncMessage('❌ Nu s-a putut deschide helper-ul. Verificați popup blocker.')
                        setTimeout(() => setSyncMessage(null), 5000)
                        setLoading(false)
                        return
                    }
                } else {
                    setSyncMessage('⚠️ Extragerea cookie-urilor a eșuat, se continuă...')
                }
            }
        } catch (scraperError) {
            console.warn('⚠️ Cookie scraper request failed:', scraperError)
            setSyncMessage('⚠️ Eroare la extragerea cookie-urilor, se continuă...')
        }
        
        // Small delay to let user see the scraping message
        await new Promise(resolve => setTimeout(resolve, 1000))
        
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

    const handleDownload = (messageId: string) => {
        window.open(`/spv/download/${messageId}`, '_blank')
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
                <div className="relative min-h-[60vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border p-6">
                    {/* Connection Status and Controls */}
                    <div className="mb-6">
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
                        
                        {/* Connection Status Alert */}
                        {(!sessionActive && connectionStatus === 'disconnected') && (
                            <Alert className="mb-4 border-orange-200 bg-orange-50 dark:border-orange-800 dark:bg-orange-950">
                                <AlertCircle className="h-4 w-4 text-orange-600" />
                                <AlertDescription className="text-orange-700 dark:text-orange-300">
                                    Nu există o conexiune activă cu ANAF. Apăsați "Conectează ANAF" pentru a vă autentifica și a activa sincronizarea mesajelor.
                                </AlertDescription>
                            </Alert>
                        )}
                        
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
                            <div className="text-center py-12">
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
                                                        <th className="text-left p-4 font-semibold text-sm w-[200px]">
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
                                                        <th className="text-left p-4 font-semibold text-sm w-[180px]">
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
                                                        <th className="text-left p-4 font-semibold text-sm w-[140px]">Data afișare</th>
                                                        <th className="text-left p-4 font-semibold text-sm w-[300px]">Detalii</th>
                                                        <th className="text-left p-4 font-semibold text-sm w-[150px]">Descarcă document</th>
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
                                                                className={`border-b hover:bg-muted/30 transition-colors duration-200 ease-in-out ${index % 2 === 0 ? 'bg-background' : 'bg-muted/10'}`}
                                                            >
                                                            <td className="p-4 align-top w-[200px]">
                                                                <div className="space-y-1">
                                                                    <div className="font-semibold text-sm truncate">{message.cif}</div>
                                                                    <div className="text-xs text-muted-foreground truncate">
                                                                        {getCompanyName(message.cif)}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[180px]">
                                                                <Badge variant={getMessageTypeBadge(message.tip)} className="whitespace-nowrap">
                                                                    {getDocumentTypeDisplay(message.tip)}
                                                                </Badge>
                                                            </td>
                                                            <td className="p-4 align-top w-[140px]">
                                                                <div className="text-sm font-medium">
                                                                    {formatRomanianDate(message.data_creare || message.formatted_date_creare || '')}
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[300px]">
                                                                <div className="text-sm leading-relaxed h-12 overflow-hidden">
                                                                    <div className="line-clamp-3">{message.detalii}</div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[150px]">
                                                                <Button
                                                                    onClick={() => handleDownload(message.anaf_id)}
                                                                    size="sm"
                                                                    variant="outline"
                                                                    className="whitespace-nowrap"
                                                                >
                                                                    <Icon iconNode={Download} className="w-4 h-4 mr-2" />
                                                                    {message.downloaded_at ? "Descarcă din nou" : "Descarcă"}
                                                                </Button>
                                                            </td>
                                                        </tr>
                                                    ))
                                                    )}
                                                    
                                                    {/* Empty placeholder rows to maintain consistent table height */}
                                                    {Array.from({ length: messagesPerPage - paginatedMessages.length - (paginatedMessages.length === 0 ? 1 : 0) }, (_, i) => (
                                                        <tr key={`empty-${i}`} className={`border-b ${(paginatedMessages.length + i) % 2 === 0 ? 'bg-background' : 'bg-muted/10'}`}>
                                                            <td className="p-4 align-top w-[200px]">
                                                                <div className="space-y-1">
                                                                    <div className="font-semibold text-sm truncate">&nbsp;</div>
                                                                    <div className="text-xs text-muted-foreground truncate">&nbsp;</div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[180px]">
                                                                <div className="whitespace-nowrap">&nbsp;</div>
                                                            </td>
                                                            <td className="p-4 align-top w-[140px]">
                                                                <div className="text-sm font-medium">&nbsp;</div>
                                                            </td>
                                                            <td className="p-4 align-top w-[300px]">
                                                                <div className="text-sm leading-relaxed h-12 overflow-hidden">
                                                                    <div className="line-clamp-3">&nbsp;</div>
                                                                </div>
                                                            </td>
                                                            <td className="p-4 align-top w-[150px]">
                                                                <div className="whitespace-nowrap">&nbsp;</div>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                            </div>
                            
                            {/* Pagination Controls */}
                            {(
                                <div className="flex items-center justify-between border-t bg-muted/20 px-4 py-3 rounded-b-lg">
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
