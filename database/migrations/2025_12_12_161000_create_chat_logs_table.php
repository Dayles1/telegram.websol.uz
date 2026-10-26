<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('chat_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_log_group_id')->nullable()->constrained()->onDelete('cascade');
            $table->bigInteger('message_id')->index();
            $table->text('message_text')->nullable();
            $table->timestamp('message_date')->nullable();
            $table->bigInteger('from_user_id')->nullable()->index();
            $table->string('from_username')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_logs');
    }
};
