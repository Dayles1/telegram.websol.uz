<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infinity_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_group_id')->constrained()->onDelete('cascade');
            $table->string('peer');
            $table->text('message_text');

            // $table->integer('loopcount')->default(0);
            $table->timestamp('last_sent')->nullable();

            $table->integer('interval')->default(60);
            $table->integer('sent_count')->default(0);
            
            $table->string('status')
                ->default('pending');

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('stopped_at')->nullable();
            $table->integer('attempts')->default(0);


            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infinity_messages');
    }
};
