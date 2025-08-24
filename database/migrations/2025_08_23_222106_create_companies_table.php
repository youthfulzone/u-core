<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('cui')->unique();
            $table->string('denumire')->nullable();
            $table->string('adresa')->nullable();
            $table->string('nrRegCom')->nullable();
            $table->date('data')->nullable();
            $table->string('telefon')->nullable();
            $table->string('fax')->nullable();
            $table->string('codPostal')->nullable();
            $table->string('act')->nullable();
            $table->string('stare_inregistrare')->nullable();
            $table->date('data_inregistrare')->nullable();
            $table->string('cod_CAEN')->nullable();
            $table->string('iban')->nullable();
            $table->boolean('statusRO_e_Factura')->default(false);
            $table->string('organFiscalCompetent')->nullable();
            $table->boolean('forma_de_proprietate')->default(false);
            $table->boolean('forma_organizare')->default(false);
            $table->boolean('forma_juridica')->default(false);
            $table->json('adresa_sediu_social')->nullable();
            $table->json('adresa_domiciliu_fiscal')->nullable();
            $table->json('tva_inregistrare')->nullable();
            $table->json('remorca_agricola')->nullable();
            $table->json('punct_de_lucru')->nullable();
            $table->json('activitate')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
