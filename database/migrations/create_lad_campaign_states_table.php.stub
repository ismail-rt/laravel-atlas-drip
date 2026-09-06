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
        $tableName = config('lad.table_names.states', 'lad_campaign_states');

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('recipient_type', 128)->nullable();
                $table->unsignedBigInteger('recipient_id');
                $table->string('campaign', 64)->index();
                $table->string('instance_key', 191)->default('default');
                $table->string('status', 32)->index();
                $table->timestamp('anchor_at');
                $table->string('last_step', 64)->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->unique(['recipient_id', 'campaign', 'instance_key'], 'lad_states_recip_camp_inst_uniq');
                $table->index(['recipient_type', 'recipient_id'], 'lad_states_morph_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('lad.table_names.states', 'lad_campaign_states');

        Schema::dropIfExists($tableName);
    }
};
