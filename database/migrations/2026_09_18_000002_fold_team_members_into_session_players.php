<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Folds team_members into session_players.
     *
     * The two described the same thing inside one app: who is taking part in
     * this session, and on which side. session_players already carried team_id,
     * so team_members added exactly one column — is_captain — while duplicating
     * user_id and guest_name. Both were written on every join, so a participant
     * existed twice with no constraint keeping the halves honest.
     *
     * No data moves: both tables are empty. The join-by-invite-code flow they
     * serve is built and shipped but has never been used — this household hosts
     * in person on one screen — so the feature is kept and its storage halved,
     * rather than dropped.
     */
    public function up(): void
    {
        Schema::table('session_players', function (Blueprint $table) {
            $table->boolean('is_captain')->default(false)->after('team_id');
        });

        Schema::dropIfExists('team_members');
    }

    public function down(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('competitors')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->boolean('is_captain')->default(false);
            $table->timestamps();
        });

        // Rebuild from session_players, which is where the data lives now.
        foreach (DB::table('session_players')->whereNotNull('team_id')->orderBy('id')->cursor() as $p) {
            DB::table('team_members')->insert([
                'team_id'    => $p->team_id,
                'user_id'    => $p->user_id,
                'guest_name' => $p->guest_name,
                'is_captain' => $p->is_captain,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ]);
        }

        Schema::table('session_players', function (Blueprint $table) {
            $table->dropColumn('is_captain');
        });
    }
};
