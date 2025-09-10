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
        Schema::connection('mongodb')->create('anaf_credentials', function (Blueprint $collection) {
            $collection->index('environment');
            $collection->index('client_id');
            $collection->index('is_active');
            $collection->index(['environment', 'is_active']);
            $collection->unique('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('anaf_credentials');
    }
};
