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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('internal_id')->nullable()->unique(); // e.g., "3361"
            $table->string('code')->nullable(); // e.g., "ARJ1078"
            $table->string('name');
            $table->text('address');
            $table->string('phone')->nullable();
            $table->string('nik')->nullable(); // National ID
            $table->decimal('geo_lat', 10, 8)->nullable();
            $table->decimal('geo_long', 11, 8)->nullable();
            $table->string('pppoe_user')->unique();
            $table->string('pppoe_pass'); // Will use encrypted cast in model
            $table->foreignId('package_id')->constrained('packages')->onDelete('restrict');
            $table->enum('status', ['active', 'suspended', 'isolated', 'offboarding'])->default('active');
            $table->date('join_date')->nullable();
            $table->string('ktp_photo_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
