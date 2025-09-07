<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model as Eloquent;

class Company extends Eloquent
{
    protected $connection = 'mongodb';

    protected $collection = 'companies';

    protected $fillable = [
        // Core company information
        'cui',
        'denumire',
        'adresa',
        'nrRegCom',
        'data',
        'telefon',
        'fax',
        'codPostal',
        'act',
        'stare_inregistrare',
        'data_inregistrare',
        'cod_CAEN',
        'iban',
        'statusRO_e_Factura',
        'organFiscalCompetent',
        'forma_de_proprietate',
        'forma_organizare',
        'forma_juridica',
        'adresa_sediu_social',
        'adresa_domiciliu_fiscal',
        'tva_inregistrare',
        'remorca_agricola',
        'punct_de_lucru',
        'activitate',
        'synced_at',
        
        // Processing status
        'status', // pending_data, processing, active, data_not_found, failed, approved
        'approved_at',
        'approved_by',
        'locked', // true/false - prevents processing and modifications
        'locked_at',
        'locked_by',
        'manual_added', // true/false - indicates if company was manually added
        'added_by', // user id who added the company manually

        // Lista Firme API specific fields
        'data_source',
        'euid',
        'registration_date',
        'company_type',
        'address_details',
        'status_details',
        'caen_codes',

        // Additional info from Lista Firme
        'full_address_info',
        'registration_status',
        'activity_code',
        'bank_account',
        'ro_invoice_status',
        'authority_name',
        'form_of_ownership',
        'organizational_form',
        'legal_form',

        // VIES API specific fields
        'country_code',
        'vat_number',
        'vat_valid',
        'vies_request_date',
        'source_api', // 'anaf', 'vies', or 'targetare' - tracks which API was used to fetch company data

        // Targetare API specific fields
        'tax_category',
        'company_status',
        'county',
        'locality',
        'street_nr',
        'street_name',
        'postal_code',
        'full_address',
        'company_id',
        'founding_year',
        'split_vat',
        'checkout_vat',
        'vat',
        'caen_activities',
        'company_name',
        'company_type_targetare',
        'has_email',
        'has_phone',
        'has_verified_phone',
        'has_administrator',
        'has_website',
        'has_fin_data',
        'employees_current',
        'targetare_synced_at',
    ];

    protected $casts = [
        // Date fields
        'data' => 'date',
        'data_inregistrare' => 'date',
        'registration_date' => 'date',
        'synced_at' => 'datetime',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
        'targetare_synced_at' => 'datetime',

        // Boolean fields
        'statusRO_e_Factura' => 'boolean',
        'forma_de_proprietate' => 'boolean',
        'forma_organizare' => 'boolean',
        'forma_juridica' => 'boolean',
        'ro_invoice_status' => 'boolean',
        'locked' => 'boolean',
        'vat_valid' => 'boolean',
        'split_vat' => 'boolean',
        'checkout_vat' => 'boolean',
        'vat' => 'boolean',
        'has_email' => 'boolean',
        'has_phone' => 'boolean',
        'has_verified_phone' => 'boolean',
        'has_administrator' => 'boolean',
        'has_website' => 'boolean',
        'has_fin_data' => 'boolean',

        // Array/JSON fields
        'adresa_sediu_social' => 'array',
        'adresa_domiciliu_fiscal' => 'array',
        'tva_inregistrare' => 'array',
        'remorca_agricola' => 'array',
        'punct_de_lucru' => 'array',
        'activitate' => 'array',
        'address_details' => 'array',
        'status_details' => 'array',
        'caen_codes' => 'array',
        'full_address_info' => 'array',
        'caen_activities' => 'array',
    ];
}
