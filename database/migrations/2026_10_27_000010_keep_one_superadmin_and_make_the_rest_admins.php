<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Asked for on 2026-10-27, after an API token of a second superadmin sent from someone
 * else's account: only config('telegram.superadmin_email') stays superadmin, every other
 * superadmin becomes "admin" (sees their own and plain users' accounts, not other admins').
 *
 * The roles before the change are kept in users_role_backup, so down() puts them back.
 * Drop that table in a later release, once nobody needs the rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users_role_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamp('backed_up_at')->useCurrent();
        });

        DB::transaction(function (): void {
            $email = (string) config('telegram.superadmin_email');
            $superadmin = $this->roleId('superadmin');
            $admin = $this->roleId('admin');

            $demoted = DB::table('users')->where('role_id', $superadmin)->where('email', '!=', $email);
            $promoted = DB::table('users')->where('email', $email)->where(fn ($q) => $q->whereNull('role_id')->orWhere('role_id', '!=', $superadmin));

            foreach ([$demoted, $promoted] as $query) {
                DB::table('users_role_backup')->insertUsing(['user_id', 'role_id'], (clone $query)->select('id', 'role_id'));
            }
            $demoted->update(['role_id' => $admin, 'updated_at' => now()]);
            $promoted->update(['role_id' => $superadmin, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::table('users_role_backup')->orderBy('user_id')->each(function (object $row): void {
            DB::table('users')->where('id', $row->user_id)->update(['role_id' => $row->role_id]);
        });

        Schema::dropIfExists('users_role_backup');
    }

    private function roleId(string $name): int
    {
        $id = DB::table('roles')->where('name', $name)->value('id');

        return (int) ($id ?? DB::table('roles')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]));
    }
};
