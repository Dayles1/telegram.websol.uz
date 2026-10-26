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
        // database/migrations/xxxx_xx_xx_create_telegram_auth_sessions_table.php
        Schema::create('telegram_auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('phone', 30);
            $table->string('status')->default('created'); // created, sms_sent, processing, need_password, success, failed
            $table->string('message_key')->nullable();
            $table->string('message')->nullable();
            $table->string('telegram_user_id')->nullable();
            $table->string('session_path')->nullable();
            $table->boolean('code_used')->default(false);
            $table->integer('attempts')->default(0);
            $table->timestamp('last_ping')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_auth_sessions');
    }
};
