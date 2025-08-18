import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Icon } from '@/components/icon'
import { SpvMessage } from './types'

interface MessagesListProps {
    messages: SpvMessage[]
    onSyncMessages: (days: number, cif?: string) => void
    loading: boolean
}

export default function MessagesList({ messages, onSyncMessages, loading }: MessagesListProps) {
    const [syncDays, setSyncDays] = useState(60)
    const [syncCif, setSyncCif] = useState('')
    const [downloadingMessages, setDownloadingMessages] = useState<Set<string>>(new Set())
    const [selectedMessages, setSelectedMessages] = useState<Set<string>>(new Set())

    const handleDownload = async (messageId: string) => {
        try {
            setDownloadingMessages(prev => new Set(prev).add(messageId))
            
            // Create a temporary link for download
            const link = document.createElement('a')
            link.href = `/spv/download/${messageId}`
            link.download = `message_${messageId}.pdf`
            link.target = '_blank'
            
            // Add to DOM, click, and remove
            document.body.appendChild(link)
            link.click()
            document.body.removeChild(link)
            
            // Remove from downloading state after a short delay
            setTimeout(() => {
                setDownloadingMessages(prev => {
                    const newSet = new Set(prev)
                    newSet.delete(messageId)
                    return newSet
                })
            }, 2000)
            
        } catch (error) {
            console.error('Download failed:', error)
            setDownloadingMessages(prev => {
                const newSet = new Set(prev)
                newSet.delete(messageId)
                return newSet
            })
        }
    }

    const handleBulkDownload = async () => {
        const messagesToDownload = Array.from(selectedMessages)
        
        for (const messageId of messagesToDownload) {
            await handleDownload(messageId)
            // Small delay between downloads to avoid overwhelming the server
            await new Promise(resolve => setTimeout(resolve, 500))
        }
        
        setSelectedMessages(new Set())
    }

    const toggleMessageSelection = (messageId: string) => {
        setSelectedMessages(prev => {
            const newSet = new Set(prev)
            if (newSet.has(messageId)) {
                newSet.delete(messageId)
            } else {
                newSet.add(messageId)
            }
            return newSet
        })
    }

    const toggleSelectAll = () => {
        if (selectedMessages.size === messages.length) {
            setSelectedMessages(new Set())
        } else {
            setSelectedMessages(new Set(messages.map(m => m.anaf_id)))
        }
    }

    const getMessageTypeBadge = (tip: string) => {
        const variants: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
            'RECIPISA': 'default',
            'RAPORT': 'secondary',
            'NOTIFICARE': 'outline',
        }
        return variants[tip] || 'secondary'
    }

    const formatFileSize = (bytes?: number) => {
        if (!bytes) return ''
        if (bytes < 1024) return `${bytes} B`
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    }

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Icon name="RefreshCw" className="h-5 w-5" />
                        Sync Messages
                    </CardTitle>
                    <CardDescription>
                        Retrieve messages from ANAF for the specified period
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <Alert>
                        <Icon name="Info" className="h-4 w-4" />
                        <AlertDescription>
                            <strong>First time setup:</strong> Click "Authenticate with ANAF" to open the ANAF website in a new tab. 
                            Use your physical token to authenticate, then return here to sync messages.
                        </AlertDescription>
                    </Alert>
                    
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <Button 
                                onClick={() => window.open('https://webserviced.anaf.ro/SPVWS2/rest/listaMesaje?zile=60', '_blank')}
                                variant="outline"
                                className="w-full"
                            >
                                <Icon name="ExternalLink" className="mr-2 h-4 w-4" />
                                Authenticate with ANAF
                            </Button>
                        </div>
                        <div>
                            <Label htmlFor="days">Days to sync</Label>
                            <Input
                                id="days"
                                type="number"
                                min="1"
                                max="365"
                                value={syncDays}
                                onChange={(e) => setSyncDays(parseInt(e.target.value) || 60)}
                                placeholder="60"
                            />
                        </div>
                        <div>
                            <Label htmlFor="cif">CIF (optional)</Label>
                            <Input
                                id="cif"
                                value={syncCif}
                                onChange={(e) => setSyncCif(e.target.value)}
                                placeholder="Filter by CIF"
                            />
                        </div>
                        <div className="flex items-end">
                            <Button 
                                onClick={() => onSyncMessages(syncDays, syncCif || undefined)}
                                disabled={loading}
                                className="w-full"
                            >
                                {loading ? (
                                    <>
                                        <Icon name="Loader2" className="mr-2 h-4 w-4 animate-spin" />
                                        Syncing...
                                    </>
                                ) : (
                                    <>
                                        <Icon name="RefreshCw" className="mr-2 h-4 w-4" />
                                        Sync Messages
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {messages.length > 0 && (
                <Card>
                    <CardContent className="py-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={selectedMessages.size === messages.length && messages.length > 0}
                                        onChange={toggleSelectAll}
                                        className="rounded border-gray-300"
                                    />
                                    <span className="text-sm">
                                        Select All ({selectedMessages.size} of {messages.length} selected)
                                    </span>
                                </label>
                            </div>
                            {selectedMessages.size > 0 && (
                                <Button 
                                    onClick={handleBulkDownload}
                                    disabled={downloadingMessages.size > 0}
                                    size="sm"
                                >
                                    <Icon name="Download" className="w-4 h-4 mr-2" />
                                    Download Selected ({selectedMessages.size})
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>
            )}

            <div className="space-y-3">
                {messages.length === 0 ? (
                    <Card>
                        <CardContent className="flex items-center justify-center h-32">
                            <div className="text-center">
                                <Icon name="Mail" className="h-12 w-12 text-muted-foreground mx-auto mb-2" />
                                <p className="text-muted-foreground">No messages found</p>
                                <p className="text-sm text-muted-foreground">
                                    Click "Sync Messages" to retrieve messages from ANAF
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    messages.map((message) => (
                        <Card key={message.id} className="relative">
                            <CardHeader className="pb-3">
                                <div className="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        checked={selectedMessages.has(message.anaf_id)}
                                        onChange={() => toggleMessageSelection(message.anaf_id)}
                                        className="mt-1 rounded border-gray-300"
                                    />
                                    <div className="flex-1 flex items-start justify-between">
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
                                                    <Icon name="Download" className="w-3 h-3 mr-1" />
                                                    Downloaded{message.file_size && ` (${formatFileSize(message.file_size)})`}
                                                </Badge>
                                            )}
                                        </div>
                                        <CardTitle className="text-lg">{message.detalii}</CardTitle>
                                        <CardDescription>
                                            Created: {message.formatted_date_creare}
                                            {message.downloaded_at && (
                                                <> • Downloaded: {message.formatted_downloaded_at}</>
                                            )}
                                        </CardDescription>
                                    </div>
                                    <Button
                                        onClick={() => handleDownload(message.anaf_id)}
                                        size="sm"
                                        variant={message.downloaded_at ? "outline" : "default"}
                                        disabled={downloadingMessages.has(message.anaf_id)}
                                    >
                                        {downloadingMessages.has(message.anaf_id) ? (
                                            <>
                                                <Icon name="Loader2" className="w-4 h-4 mr-2 animate-spin" />
                                                Downloading...
                                            </>
                                        ) : (
                                            <>
                                                <Icon name="Download" className="w-4 h-4 mr-2" />
                                                {message.downloaded_at ? "Re-download" : "Download"}
                                            </>
                                        )}
                                    </Button>
                                    </div>
                                </div>
                            </CardHeader>
                            {message.id_solicitare && (
                                <CardContent className="pt-0">
                                    <div className="text-sm text-muted-foreground">
                                        Request ID: {message.id_solicitare}
                                    </div>
                                </CardContent>
                            )}
                        </Card>
                    ))
                )}
            </div>
        </div>
    )
}