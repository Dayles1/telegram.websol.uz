<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            $table->text('caption')->nullable();
            $table->string('media_type')->nullable()->index();
            $table->string('media_file_name')->nullable();
            $table->string('media_mime')->nullable();
            $table->bigInteger('media_size')->nullable();
            $table->string('media_local_path')->nullable();
            $table->string('thumb_local_path')->nullable();
            $table->text('provider_file_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            $table->dropColumn([
                'caption',
                'media_type',
                'media_file_name',
                'media_mime',
                'media_size',
                'media_local_path',
                'thumb_local_path',
                'provider_file_id',
            ]);
        });
    }
};
