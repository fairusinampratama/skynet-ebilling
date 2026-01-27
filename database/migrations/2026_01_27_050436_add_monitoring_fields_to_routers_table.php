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
        Schema::table('routers', function (Blueprint $table) {
            $table->integer('current_online_count')->default(0)->after('last_scan_customers_count');
            $table->integer('cpu_load')->nullable()->after('current_online_count');
            $table->string('uptime')->nullable()->after('cpu_load');
            $table->string('version')->nullable()->after('uptime'); // e.g. 6.48.6
            $table->string('board_name')->nullable()->after('version'); // e.g. CCR1009
            $table->timestamp('last_health_check_at')->nullable()->after('last_scanned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn([
                'current_online_count',
                'cpu_load',
                'uptime',
                'version',
                'board_name',
                'last_health_check_at',
            ]);
        });
    }
};
