<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\Laravel\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mongodb';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('mongodb')->create('efactura_invoices', function (Blueprint $collection) {
            $collection->index('company_id');
            $collection->index('cui');
            $collection->index('invoice_id');
            $collection->index('upload_index');
            $collection->index('invoice_number');
            $collection->index('upload_status');
            $collection->index('status');
            $collection->index('invoice_date');
            $collection->index(['cui', 'upload_status']);
            $collection->index(['cui', 'invoice_date']);
            $collection->index(['company_id', 'upload_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('efactura_invoices');
    }
};
