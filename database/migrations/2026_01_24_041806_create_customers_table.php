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
            $table->string('pppoe_pass');
            
            // Relations
            $table->foreignId('package_id')->constrained()->onDelete('restrict');
            $table->foreignId('router_id')->nullable()->constrained()->onDelete('set null');
            
            // Status
            $table->enum('status', ['active', 'suspended', 'isolated', 'offboarding'])->default('active');
            
            // Geolocation
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_long', 10, 7)->nullable();
            
            // Metadata
            $table->date('join_date')->nullable();
            $table->string('ktp_photo_url')->nullable();
            
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
