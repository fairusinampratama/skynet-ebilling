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
        Schema::table('wa_campaigns', function (Blueprint $table) {
            $table->string('target_type')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wa_campaigns', function (Blueprint $table) {
            // Reversing an enum change in sqlite can be tricky, 
            // string is safer to keep as down() rollback.
            $table->string('target_type')->change();
        });
    }
};
