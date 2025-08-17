import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Icon } from '@/components/icon'

interface SessionStatusProps {
    active: boolean
    expiry: string | null
    onClearSession: () => void
}

export default function SessionStatus({ active, expiry, onClearSession }: SessionStatusProps) {
    const [loading, setLoading] = useState(false)

    const handleClearSession = async () => {
        setLoading(true)
        try {
            await onClearSession()
        } finally {
            setLoading(false)
        }
    }

    const getTimeUntilExpiry = () => {
        if (!expiry) return null
        
        const expiryDate = new Date(expiry)
        const now = new Date()
        const diff = expiryDate.getTime() - now.getTime()
        
        if (diff <= 0) return 'Expired'
        
        const hours = Math.floor(diff / (1000 * 60 * 60))
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))
        
        if (hours > 0) {
            return `${hours}h ${minutes}m`
        }
        return `${minutes}m`
    }

    return (
        <div className="flex items-center gap-2">
            <Badge variant={active ? "default" : "secondary"} className="gap-1">
                <Icon 
                    name={active ? "Shield" : "ShieldOff"} 
                    className="h-3 w-3" 
                />
                {active ? "Session Active" : "No Session"}
            </Badge>
            {active && expiry && (
                <span className="text-xs text-muted-foreground">
                    Expires: {getTimeUntilExpiry()}
                </span>
            )}
            {!active && (
                <span className="text-xs text-muted-foreground">
                    Click "Authenticate with ANAF" to connect
                </span>
            )}
            {active && (
                <Button 
                    variant="outline" 
                    size="sm" 
                    onClick={handleClearSession}
                    disabled={loading}
                >
                    {loading ? (
                        <Icon name="Loader2" className="h-4 w-4 animate-spin" />
                    ) : (
                        <Icon name="LogOut" className="h-4 w-4" />
                    )}
                </Button>
            )}
        </div>
    )
}