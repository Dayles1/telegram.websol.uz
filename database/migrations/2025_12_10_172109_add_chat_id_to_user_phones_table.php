<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
        Schema::table('user_phones', function (Blueprint $table) {
            $table->string('telegram_user_id')->nullable()->after('phone');
        });
    }

    public function down()
    {
        Schema::table('user_phones', function (Blueprint $table) {
            $table->dropColumn('telegram_user_id');
        });
    }

};
