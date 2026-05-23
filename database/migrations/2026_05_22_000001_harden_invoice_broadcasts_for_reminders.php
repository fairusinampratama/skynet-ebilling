<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_broadcasts')) {
            return;
        }

        Schema::table('invoice_broadcasts', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_broadcasts', 'channel')) {
                $table->string('channel')->default('whatsapp')->after('type');
            }

            if (! Schema::hasColumn('invoice_broadcasts', 'message')) {
                $table->text('message')->nullable()->after('status');
            }
        });

        DB::table('invoice_broadcasts')
            ->where('status', 'success')
            ->update(['status' => 'sent']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invoice_broadcasts MODIFY channel VARCHAR(32) NOT NULL DEFAULT 'whatsapp'");
            DB::statement("ALTER TABLE invoice_broadcasts MODIFY status VARCHAR(32) NOT NULL DEFAULT 'sent'");
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                'DELETE duplicate FROM invoice_broadcasts duplicate
                 INNER JOIN invoice_broadcasts keeper
                    ON keeper.invoice_id = duplicate.invoice_id
                   AND keeper.type = duplicate.type
                   AND keeper.channel = duplicate.channel
                   AND keeper.id < duplicate.id'
            );
        }

        Schema::table('invoice_broadcasts', function (Blueprint $table) {
            $table->unique(['invoice_id', 'type', 'channel'], 'invoice_broadcasts_invoice_type_channel_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_broadcasts')) {
            return;
        }

        Schema::table('invoice_broadcasts', function (Blueprint $table) {
            $table->dropUnique('invoice_broadcasts_invoice_type_channel_unique');
        });
    }
};
