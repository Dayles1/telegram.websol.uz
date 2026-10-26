<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_id')->constrained('catalogs')->onDelete('cascade');
            $table->foreignId('user_phone_id')->constrained('user_phones')->onDelete('cascade');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['catalog_id', 'user_phone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_usages');
    }
};
