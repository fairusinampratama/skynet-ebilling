<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->string('reference')->nullable(); // Tripay Reference
            $table->foreignId('admin_id')->nullable()->constrained('users')->onDelete('set null'); // Who recorded payment
            $table->decimal('amount', 10, 2);
            $table->string('channel')->nullable(); // QRIS, MYBVA, etc
            $table->enum('method', ['cash', 'transfer', 'payment_gateway'])->default('cash');
            $table->string('status')->default('paid'); // paid, pending, failed
            $table->string('proof_url')->nullable(); // Path to receipt image
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
