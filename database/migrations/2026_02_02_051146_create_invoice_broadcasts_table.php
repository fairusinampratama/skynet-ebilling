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
        Schema::create('invoice_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['h-5', 'h-day', 'h-plus-3']);
            $table->string('status')->default('success'); // success, failed
            $table->timestamp('sent_at')->useCurrent();
            $table->string('message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            // Prevent duplicate broadcasts of the same type for the same invoice
            $table->unique(['invoice_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_broadcasts');
    }
};
