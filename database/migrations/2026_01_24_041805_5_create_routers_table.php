<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->integer('port')->default(8728);
            $table->integer('winbox_port')->nullable();
            $table->string('username');
            $table->text('password'); // Encrypted
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['ip_address', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
