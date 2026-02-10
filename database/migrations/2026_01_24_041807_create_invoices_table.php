<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique(); // Consolidated from separate migration
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->date('period'); // e.g., "2026-02-01" for February billing
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['unpaid', 'paid', 'void'])->default('unpaid');
            $table->string('payment_link')->nullable();
            $table->date('due_date');
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();
            
            // Ensure one invoice per customer per period
            $table->unique(['customer_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
