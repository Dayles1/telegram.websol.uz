<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A phone becomes a full Telegram account record: its login state, its profile
 * and its health live on the row itself instead of in telegram_auth_sessions.
 *
 * Expand only. Existing rows were written on a successful login, so they start
 * as "authorized"; rows whose session was already cleared become "logged_out".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_phones', function (Blueprint $table) {
            $table->string('status', 32)->default('authorized')->after('phone')->index()
                ->comment('TelegramAccountStatus: login step or session health');
            $table->string('first_name')->nullable()->after('telegram_user_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('username')->nullable()->after('last_name');
            $table->string('password_hint')->nullable()->after('session_path')
                ->comment('2FA hint Telegram returned, shown on the password step');
            $table->string('last_error', 191)->nullable()->after('password_hint')
                ->comment('Error key of the last failed login or session check');
            $table->timestamp('login_started_at')->nullable()->after('last_error');
            $table->timestamp('authorized_at')->nullable()->after('login_started_at');
            $table->timestamp('last_checked_at')->nullable()->after('authorized_at');
            $table->timestamp('flood_until')->nullable()->after('last_checked_at')
                ->comment('Telegram asked this account to wait; no sending before this moment');
            $table->timestamp('last_sent_at')->nullable()->after('flood_until');

            $table->index('phone');
            $table->index('telegram_user_id');
        });

        DB::table('user_phones')->whereNull('session_path')->update(['status' => 'logged_out']);
        DB::table('user_phones')->update(['authorized_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('user_phones', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropIndex(['telegram_user_id']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status', 'first_name', 'last_name', 'username', 'password_hint', 'last_error',
                'login_started_at', 'authorized_at', 'last_checked_at', 'flood_until', 'last_sent_at',
            ]);
        });
    }
};
