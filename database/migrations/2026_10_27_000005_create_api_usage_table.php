<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which API endpoints are actually called, by whom, how often: one counter row
 * per day, endpoint and user. Answers "which endpoints can we delete" with data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('method', 8);
            $table->string('endpoint', 191)->comment('Route URI pattern, e.g. api/groups/{group}');
            $table->unsignedBigInteger('user_id')->default(0)->comment('0 = no token (NULL would break the unique key)');
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('errors')->default(0)->comment('Responses with status >= 400');
            $table->string('last_ip', 45)->nullable();
            $table->string('last_user_agent', 255)->nullable();
            $table->timestamp('last_called_at')->nullable();

            $table->unique(['day', 'method', 'endpoint', 'user_id']);
            $table->index(['endpoint', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage');
    }
};
