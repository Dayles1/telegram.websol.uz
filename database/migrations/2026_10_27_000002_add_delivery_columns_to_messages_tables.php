<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the per-account send worker needs: a claim mark on each message so a row
 * is sent once, the creator of a group (a superadmin may send from someone
 * else's phone), and indexes for the queries the worker and the pages run.
 *
 * Expand only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('send_at')
                ->comment('Set when a worker takes the row (status sending)');

            $table->index(['message_group_id', 'status']);
            $table->index(['status', 'sent_at']);
            $table->index(['status', 'claimed_at']);
            $table->index('created_at'); // analytics periods
        });

        Schema::table('message_groups', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('user_phone_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->after('message_text');
            $table->timestamp('finished_at')->nullable()->after('started_at');

            $table->index(['status', 'created_at']);
        });

        Schema::table('telegram_message_tasks', function (Blueprint $table) {
            $table->index(['sender_phone_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_message_tasks', function (Blueprint $table) {
            $table->dropIndex(['sender_phone_id', 'status']);
        });

        Schema::table('message_groups', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['started_at', 'finished_at']);
        });

        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->dropIndex(['message_group_id', 'status']);
            $table->dropIndex(['status', 'sent_at']);
            $table->dropIndex(['status', 'claimed_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn('claimed_at');
        });
    }
};
