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
        Schema::create('anaf_global_sessions', function (Blueprint $table) {
            $table->id();
            $table->json('cookies')->comment('ANAF session cookies');
            $table->json('browser_info')->nullable()->comment('Browser information for each cookie');
            $table->json('metadata')->nullable()->comment('Additional metadata like domains, expiry times');
            $table->timestamp('scraped_at')->comment('When cookies were scraped');
            $table->timestamp('expires_at')->comment('When cookies expire');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anaf_global_sessions');
    }
};
