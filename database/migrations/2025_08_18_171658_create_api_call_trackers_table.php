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
        Schema::create('api_call_trackers', function (Blueprint $table) {
            $table->id();
            $table->string('api_name')->default('ANAF');
            $table->integer('calls_made')->default(0);
            $table->integer('calls_limit')->default(100);
            $table->json('errors')->nullable();
            $table->timestamp('reset_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_call_trackers');
    }
};
