<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop Foreign Keys & Columns from Child Tables
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'router_id')) {
                // Drop FK first
                $table->dropForeign(['router_id']);
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'router_id')) {
                // Then drop column in a separate schema call for SQLite safety
                $table->dropColumn('router_id');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'router_id')) {
                // Drop FK
                $table->dropForeign(['router_id']);
                
                // Drop index if exists (try-catch for Laravel 12 compatibility)
                try {
                    $table->dropIndex('packages_router_id_mikrotik_profile_index');
                } catch (\Exception $e) {
                    // Index doesn't exist, ignore
                }
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (Schema::hasColumn('packages', 'router_id')) {
                $table->dropColumn('router_id');
            }
        });

        // 2. Drop Router Related Tables
        Schema::dropIfExists('router_profiles');
        Schema::dropIfExists('routers');
    }

    public function down(): void
    {
        // Re-create tables (structure only, data is lost)
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->string('username');
            $table->string('password'); // encrypted
            $table->integer('port')->default(8728);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('router_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('bandwidth')->nullable();
            $table->string('rate_limit')->nullable();
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->foreignId('router_id')->nullable()->constrained('routers')->onDelete('set null');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('router_id')->nullable()->constrained('routers')->onDelete('set null');
        });
    }
};
