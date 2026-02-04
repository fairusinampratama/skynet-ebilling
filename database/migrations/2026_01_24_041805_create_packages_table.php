<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->nullable()->constrained('routers')->onDelete('set null');
            $table->string('name'); // Removed unique constraint as per later updates
            $table->string('mikrotik_profile')->nullable();
            $table->string('rate_limit')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('bandwidth_label');
            $table->timestamps();

            // Composite index for fast lookups during Router Sync
            $table->index(['router_id', 'mikrotik_profile']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
