import { Head } from '@inertiajs/react'
import { Icon } from '@/components/icon'
import { FileText } from 'lucide-react'

interface ViewerMessage {
    id: string
    anaf_id: string
    detalii: string
    cif: string
    tip: string
    file_name: string
    file_size: number
    content_type: string
    formatted_date_creare: string
}

interface ViewerProps {
    message: ViewerMessage
}

export default function Viewer({ message }: ViewerProps) {
    return (
        <div className="min-h-screen bg-gray-100 dark:bg-gray-900">
            <Head title={`Vizualizator - ${message.file_name}`} />

            {/* Full Screen PDF Viewer */}
            <div className="w-full h-screen">
                {message.content_type === 'application/pdf' ? (
                    <iframe
                        src={`/spv/view/${message.anaf_id}`}
                        className="w-full h-full border-0"
                        title={message.file_name}
                    />
                ) : (
                    <div className="flex items-center justify-center h-full">
                        <div className="text-center">
                            <Icon iconNode={FileText} className="w-16 h-16 mx-auto text-gray-400 mb-4" />
                            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                                Previzualizare indisponibilă
                            </h3>
                            <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                Tipul de fișier {message.content_type} nu poate fi afișat în browser
                            </p>
                            <a
                                href={`/spv/view/${message.anaf_id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-blue-600 hover:text-blue-800 underline"
                            >
                                Deschide în fereastră nouă
                            </a>
                        </div>
                    </div>
                )}
            </div>
        </div>
    )
}