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
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'ktp_photo_path',
                'ktp_external_url',
                'previous_profile',
                'pppoe_pass',
                'internal_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('ktp_photo_path')->nullable();
            $table->string('ktp_external_url')->nullable();
            $table->string('previous_profile')->nullable();
            $table->string('pppoe_pass')->nullable();
            $table->string('internal_id')->nullable();
        });
    }
};
