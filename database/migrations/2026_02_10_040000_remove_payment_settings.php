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
        $keys = [
            'payment_channels',
            'tripay_api_key',
            'tripay_private_key',
            'tripay_merchant_code',
            'tripay_environment'
        ];

        DB::table('settings')->whereIn('key', $keys)->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We cannot easily reverse data deletion without a backup.
        // This is a destructive operation intended to clean up.
    }
};
