<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Files sent with a message, and repeating sends.
 *
 * attachments: one uploaded file (up to 50 MB) in storage/app/private/attachments.
 * The file is deleted from disk once nothing can send it any more; the row stays
 * for history (removed_at).
 *
 * send_loops: a send that starts again a fixed time after each round finishes.
 * Every round is an ordinary message group (message_groups.send_loop_id).
 *
 * Expand only: new tables and nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 500)->comment('Relative to the local disk');
            $table->string('original_name');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size');
            $table->string('kind', 16)->comment('photo | document: how it is sent');
            $table->timestamp('removed_at')->nullable()->index()->comment('File deleted from disk');
            $table->timestamps();
        });

        Schema::create('send_loops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_phone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('active')->comment('LoopStatus');
            $table->text('message_text')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('catalog_ids')->nullable()->comment('Peers are read from these catalogs every round');
            $table->json('peers')->nullable()->comment('Peers given directly');
            $table->unsignedInteger('every_minutes')->comment('Pause between the end of a round and the next one');
            $table->unsignedInteger('max_rounds')->nullable()->comment('null = until stopped');
            $table->unsignedInteger('rounds_done')->default(0);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_run_at']);
        });

        Schema::table('message_groups', function (Blueprint $table) {
            $table->foreignId('attachment_id')->nullable()->after('message_text')
                ->constrained()->nullOnDelete();
            $table->foreignId('send_loop_id')->nullable()->after('attachment_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('send_loop_id');
            $table->dropConstrainedForeignId('attachment_id');
        });

        Schema::dropIfExists('send_loops');
        Schema::dropIfExists('attachments');
    }
};
