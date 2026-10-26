<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_groups', function (Blueprint $table) {
            $table->text('message_text')->nullable()->after('user_phone_id');
        });

        // Backfill eski telegram_messages dan
        DB::statement("
            UPDATE message_groups mg
            SET message_text = (
                SELECT tm.message_text
                FROM telegram_messages tm
                WHERE tm.message_group_id = mg.id
                  AND tm.message_text IS NOT NULL
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1 FROM telegram_messages tm2
                WHERE tm2.message_group_id = mg.id
                  AND tm2.message_text IS NOT NULL
            )
        ");
    }

    public function down(): void
    {
        Schema::table('message_groups', function (Blueprint $table) {
            $table->dropColumn('message_text');
        });
    }
};
