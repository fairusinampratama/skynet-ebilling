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
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone');
                $table->text('address');
                $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
                $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
                $table->string('pppoe_username')->unique();
                $table->text('pppoe_password'); // encrypted
                $table->enum('status', ['active', 'suspended', 'inactive'])->default('active');
                $table->string('ktp_number')->nullable();
                $table->string('ktp_photo_url')->nullable();
                $table->string('ktp_external_url')->nullable();
                $table->string('gps_coordinates')->nullable();
                $table->date('installation_date')->nullable();
                $table->integer('due_day')->default(5);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
