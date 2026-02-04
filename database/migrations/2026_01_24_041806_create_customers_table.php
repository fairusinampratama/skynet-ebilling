<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->nullable();
            $table->string('internal_id')->nullable();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('nik')->nullable();
            
            // PPPoE Credentials
            $table->string('pppoe_user')->unique();
            $table->string('pppoe_pass')->nullable(); // Made nullable for hybrid workflow
            
            // Relations
            $table->foreignId('package_id')->constrained()->onDelete('restrict');
            $table->foreignId('router_id')->nullable()->constrained()->onDelete('set null');
            
            // Status & Workflow
            // Default changed to 'pending_installation' for hybrid workflow 
            // Note: Enum list here is illustrative of application logic. 
            // Often just string in DB is flexible, but migration had enum before.
            // Hybrid update changed it to string with default.
            $table->string('status')->default('pending_installation'); 

            $table->boolean('is_online')->default(false);
            $table->string('previous_profile')->nullable(); // For isolation handling
            
            // Geolocation
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_long', 10, 7)->nullable();
            
            // Metadata
            $table->date('join_date')->nullable();
            
            // KTP / Photos
            $table->string('ktp_photo_url')->nullable(); // Old? Or redundant? Kept for safety.
            $table->string('ktp_photo_path')->nullable();
            $table->string('ktp_external_url')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('pppoe_user');
            $table->index('status');
            $table->index(['package_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
