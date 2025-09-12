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
        Schema::create('efactura_token_histories', function (Blueprint $table) {
            $table->id();
            
            // Token identification and metadata
            $table->string('token_id')->unique(); // Hash of token for identification
            $table->string('token_type')->default('access'); // 'access' or 'refresh'
            $table->string('status')->default('active'); // 'active', 'expired', 'revoked', 'compromised'
            
            // Encrypted token storage - NEVER store plaintext tokens
            $table->longText('encrypted_token'); // AES-256 encrypted token
            $table->longText('encrypted_refresh_token')->nullable(); // Associated refresh token
            
            // Token lifecycle tracking
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->integer('usage_count')->default(0);
            
            // Security and audit fields
            $table->string('client_id'); // ANAF client ID
            $table->json('scopes')->nullable(); // Token scopes/permissions
            $table->string('issued_ip')->nullable(); // IP where token was issued
            $table->string('last_used_ip')->nullable(); // Last IP used
            $table->json('user_agent')->nullable(); // Browser/client info
            
            // Revocation and security tracking
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable(); // 'manual', 'compromised', 'expired'
            $table->string('revoked_by')->nullable(); // User who initiated revocation
            $table->text('security_notes')->nullable(); // Manual notes about token security
            
            // ANAF-specific tracking for manual revocation requests
            $table->string('anaf_revocation_request_id')->nullable(); // Track ANAF support requests
            $table->timestamp('anaf_revocation_requested_at')->nullable();
            $table->string('anaf_revocation_status')->nullable(); // 'pending', 'completed', 'denied'
            
            // Parent token tracking for refresh chains
            $table->unsignedBigInteger('parent_token_id')->nullable(); // Links refreshed tokens
            $table->foreign('parent_token_id')->references('id')->on('efactura_token_histories');
            
            $table->timestamps();
            
            // Indexes for performance and security queries
            $table->index(['status', 'expires_at']);
            $table->index(['client_id', 'status']);
            $table->index(['issued_at', 'expires_at']);
            $table->index(['last_used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('efactura_token_histories');
    }
};
