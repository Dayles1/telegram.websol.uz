<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per API call of a logged method (POST by default, config telegram.api_log):
 * what the client sent and what it got back, secrets masked. A new table only,
 * nothing existing changes; monitoring:prune keeps it short.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 8);
            $table->string('endpoint', 191)->comment('Route URI pattern, e.g. api/groups/{group}');
            $table->string('path', 255)->comment('The real path, e.g. api/groups/15');
            $table->unsignedBigInteger('user_id')->nullable()->comment('NULL = no token');
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->mediumText('request_body')->nullable()->comment('JSON, secrets masked, cut at api_log.max_body');
            $table->mediumText('response_body')->nullable()->comment('JSON or text, secrets masked, cut at api_log.max_body');
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['endpoint', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
