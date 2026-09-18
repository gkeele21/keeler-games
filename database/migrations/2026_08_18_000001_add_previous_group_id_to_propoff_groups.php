<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a group to the one it continues from — last year's Super Bowl party
     * becoming this year's.
     *
     * PropOff is annual, and groups are per-event, so a fresh February group
     * starts with nobody in it. The "is this you?" claim step only searches the
     * current group, which means without a lineage link it can never recognise
     * a returning player and every one of them registers again.
     *
     * Members are deliberately NOT copied forward when the link is made. Doing
     * that would put 25 people on the leaderboard with zero answers before
     * anyone turns up. Instead the claim step searches back along this chain,
     * and a person is attached to the new group only when they actually arrive
     * and confirm who they are — which also carries their identity, and so
     * their history, across the year.
     */
    public function up(): void
    {
        Schema::table('propoff_groups', function (Blueprint $table) {
            $table->foreignId('previous_group_id')
                ->nullable()
                ->after('event_id')
                ->constrained('propoff_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('propoff_groups', function (Blueprint $table) {
            $table->dropForeign(['previous_group_id']);
            $table->dropColumn('previous_group_id');
        });
    }
};
