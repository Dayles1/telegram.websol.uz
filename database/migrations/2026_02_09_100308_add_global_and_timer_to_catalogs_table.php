<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('catalogs', function (Blueprint $table) {
            $table->boolean('is_global')->default(false)->after('title');
            $table->unsignedSmallInteger('timer_minutes')->nullable()->after('is_global');
        });
    }

    public function down(): void
    {
        Schema::table('catalogs', function (Blueprint $table) {
            $table->dropColumn(['is_global', 'timer_minutes']);
        });
    }
};
