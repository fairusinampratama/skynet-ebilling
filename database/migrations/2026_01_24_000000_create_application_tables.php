<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Creates all tables in final state
     */
    public function up(): void
    {
        // Areas table
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Packages table
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('speed_mbps');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Customers table
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

        // Invoices table
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('period');
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['unpaid', 'paid', 'overdue'])->default('unpaid');
            $table->date('due_date');
            $table->timestamps();
            
            $table->unique(['customer_id', 'period']);
        });

        // Transactions table  
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('channel', ['whatsapp', 'manual'])->default('manual');
            $table->enum('method', ['cash', 'transfer', 'qris', 'other'])->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('verified');
            $table->string('proof_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Settings table
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Invoice broadcasts table
        Schema::create('invoice_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->enum('channel', ['whatsapp', 'email', 'sms'])->default('whatsapp');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('message')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            
            $table->index(['invoice_id', 'channel']);
        });

        // Activity log table (for spatie/laravel-activitylog)
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->timestamps();
            
            $table->index('log_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('invoice_broadcasts');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('areas');
    }
};
