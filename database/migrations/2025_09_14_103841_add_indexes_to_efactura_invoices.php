<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create MongoDB indexes for better performance with high volume
        DB::collection('efactura_invoices')->raw(function ($collection) {
            // Index on download_id for fast duplicate checking
            $collection->createIndex(['download_id' => 1], ['unique' => true]);

            // Index on cui for filtering by company
            $collection->createIndex(['cui' => 1]);

            // Compound index for cui + invoice_date for date range queries
            $collection->createIndex(['cui' => 1, 'invoice_date' => -1]);

            // Index on sync_id for tracking job progress
            $collection->createIndex(['sync_id' => 1]);

            // Index on archived_at for recent invoice queries
            $collection->createIndex(['archived_at' => -1]);

            // Text index for invoice search by number and supplier name
            $collection->createIndex([
                'invoice_number' => 'text',
                'supplier_name' => 'text',
                'customer_name' => 'text'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the indexes
        DB::collection('efactura_invoices')->raw(function ($collection) {
            $collection->dropIndex(['download_id' => 1]);
            $collection->dropIndex(['cui' => 1]);
            $collection->dropIndex(['cui' => 1, 'invoice_date' => -1]);
            $collection->dropIndex(['sync_id' => 1]);
            $collection->dropIndex(['archived_at' => -1]);
            $collection->dropIndex([
                'invoice_number' => 'text',
                'supplier_name' => 'text',
                'customer_name' => 'text'
            ]);
        });
    }
};
