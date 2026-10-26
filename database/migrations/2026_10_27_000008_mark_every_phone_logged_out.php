<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One-off, asked for on 2026-10-26: every phone goes to "logged_out" in the database, so
 * every account is connected again from the panel. Telegram is not touched: session files
 * stay where they are, a new login deletes the old file first (RequestLoginCode).
 * Pending messages of these accounts are failed by the next sweep ("session_revoked").
 *
 * The statuses before the change are kept in user_phones_status_backup, so down() puts
 * them back. Drop that table in a later release, once nobody needs the rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_phones_status_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('user_phone_id')->primary();
            $table->string('status', 32);
            $table->timestamp('backed_up_at')->useCurrent();
        });

        DB::transaction(function (): void {
            DB::table('user_phones_status_backup')->insertUsing(
                ['user_phone_id', 'status'],
                DB::table('user_phones')->select('id', 'status')->where('status', '!=', 'logged_out'),
            );
            DB::table('user_phones')->where('status', '!=', 'logged_out')
                ->update(['status' => 'logged_out', 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::table('user_phones_status_backup')->orderBy('user_phone_id')->each(function (object $row): void {
            DB::table('user_phones')->where('id', $row->user_phone_id)->where('status', 'logged_out')
                ->update(['status' => $row->status]);
        });

        Schema::dropIfExists('user_phones_status_backup');
    }
};
