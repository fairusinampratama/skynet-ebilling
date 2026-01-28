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
        if (!Schema::hasColumn('routers', 'isolation_profile')) {
            Schema::table('routers', function (Blueprint $table) {
                $table->string('isolation_profile')->nullable()->after('password');
            });
        }

        if (!Schema::hasColumn('customers', 'previous_profile')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('previous_profile')->nullable()->after('pppoe_pass');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('isolation_profile');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('previous_profile');
        });
    }
};
