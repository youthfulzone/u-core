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
        Schema::connection('mongodb')->create('efactura_tokens', function (Blueprint $collection) {
            $collection->index('company_id');
            $collection->index('cui');
            $collection->index('client_id');
            $collection->index('status');
            $collection->index('expires_at');
            $collection->index(['cui', 'status']);
            $collection->index(['client_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('efactura_tokens');
    }
};
