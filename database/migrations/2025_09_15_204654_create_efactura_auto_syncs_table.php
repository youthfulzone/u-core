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
        Schema::create('efactura_auto_syncs', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('schedule_time')->default('03:00'); // HH:MM format
            $table->integer('sync_days')->default(60); // Number of days to sync
            $table->string('timezone')->default('Europe/Bucharest');
            $table->timestamp('last_run')->nullable();
            $table->timestamp('next_run')->nullable();
            $table->json('last_report')->nullable(); // Store last sync report
            $table->string('status')->default('idle'); // idle, running, completed, failed
            $table->text('last_error')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->boolean('email_reports')->default(true);
            $table->string('email_recipients')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('efactura_auto_syncs');
    }
};
