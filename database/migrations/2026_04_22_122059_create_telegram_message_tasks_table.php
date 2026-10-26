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
        Schema::create('telegram_message_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sender_phone_id')
                ->constrained('user_phones')
                ->cascadeOnDelete();

            $table->string('target_tg_id')->nullable()->index();
            $table->string('target_phone')->nullable()->index();

            $table->text('message_text');

            $table->string('status')->default('pending')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->json('meta')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_message_tasks');
    }
};
