<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The journal of everything worth knowing later: logins, session deaths, flood
 * waits, worker crashes, failed jobs. Warnings and errors with the same
 * fingerprint are folded into one row (occurrences, last_seen_at) until someone
 * marks them resolved, so the monitoring page stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16)->comment('info | warning | error | critical');
            $table->string('kind', 32)->comment('EventKind: login, session, sending, flood, worker, queue, api, system');
            $table->string('message');
            $table->string('error_key', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_phone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_group_id')->nullable()->constrained()->nullOnDelete();
            $table->json('context')->nullable();
            $table->string('fingerprint', 64)->index();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable()->comment('Last time it was sent to the alert bot');
            $table->timestamps();

            $table->index(['level', 'resolved_at']);
            $table->index(['kind', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_events');
    }
};
