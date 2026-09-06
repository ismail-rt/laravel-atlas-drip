<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = config('lad.table_names.logs', 'lad_notification_logs');

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('recipient_type', 128)->nullable();
                $table->unsignedBigInteger('recipient_id')->nullable()->index();
                $table->string('campaign', 64)->index();
                $table->string('step', 64);
                $table->string('dedupe_key', 191)->unique();
                $table->timestamp('sent_at')->index();
                $table->timestamps();

                $table->index(['recipient_id', 'campaign', 'sent_at'], 'lad_logs_recip_camp_sent_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('lad.table_names.logs', 'lad_notification_logs');

        Schema::dropIfExists($tableName);
    }
};
