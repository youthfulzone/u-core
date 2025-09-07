export interface SpvMessage {
    id: string
    anaf_id: string
    detalii: string
    cif: string
    data_creare: string
    id_solicitare: string | null
    tip: string
    downloaded_at: string | null
    downloaded_by: number | null
    file_path: string | null
    file_size: number | null
    formatted_date_creare: string
    formatted_downloaded_at: string
    company_name?: string | null
    company_source?: string | null
}

export interface SpvRequest {
    id: string
    anaf_id_solicitare: string | null
    tip: string
    cui: string
    an: number | null
    luna: number | null
    motiv: string | null
    numar_inregistrare: string | null
    cui_pui: string | null
    status: 'pending' | 'processed' | 'error'
    parametri: Record<string, any>
    response_data: Record<string, any> | null
    error_message: string | null
    processed_at: string | null
    formatted_processed_at: string
    created_at: string
    updated_at: string
}