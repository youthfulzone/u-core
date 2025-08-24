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
        Schema::create('company_queues', function (Blueprint $table) {
            $table->id();
            $table->string('cui');
            $table->string('status')->default('pending'); // pending, processing, approved, rejected
            $table->json('anaf_data')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('processed_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_queues');
    }
};
