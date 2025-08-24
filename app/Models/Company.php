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
    ];

    protected $casts = [
        // Date fields
        'data' => 'date',
        'data_inregistrare' => 'date',
        'registration_date' => 'date',
        'synced_at' => 'datetime',

        // Boolean fields
        'statusRO_e_Factura' => 'boolean',
        'forma_de_proprietate' => 'boolean',
        'forma_organizare' => 'boolean',
        'forma_juridica' => 'boolean',
        'ro_invoice_status' => 'boolean',

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
    ];
}
