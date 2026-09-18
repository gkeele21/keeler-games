<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2, expand step: stand up the unified `games` and `competitors`
     * tables alongside the four they will eventually replace
     * (game_sessions + scored_games, teams + scored_game_competitors).
     *
     * Nothing is dropped and no existing table is altered, so the running app
     * is unaffected. A later contract migration repoints the dependents and
     * removes the originals.
     *
     * ID convention — deliberate, and load-bearing:
     *   online rows      keep their source id  (game_sessions.id, teams.id)
     *   scorekeeper rows are offset by OFFSET  (scored_games.id + OFFSET, etc.)
     *
     * Preserving the online ids is what keeps the cutover cheap: every trivia
     * dependent (session_questions, session_cards, answer_reveals, game_states,
     * game_session_players) can be repointed by a straight column copy, and
     * critically, game_states.state_data holds raw team ids in its "team_order"
     * array — no FK covers that, and because team ids are unchanged it needs no
     * rewriting at all. Only scorekeeper rows shift.
     *
     * COLUMN NAMING — union, not rename. `games` carries both source column
     * sets verbatim rather than folding synonyms together (host_user_id AND
     * created_by_user_id; status AND is_complete; completed_at AND ended_at).
     * Measured against the codebase, normalising those names would have meant
     * 162 reference changes, 41 of them inside queries — `is_complete` alone is
     * 62 refs across 27 queries. Bundling a rename that size into a table merge
     * is risk for cosmetic gain, so each kind keeps the column names its code
     * already uses and the pairs sit NULL for the other kind. Normalising them
     * later is an independent, optional change.
     *
     * Keeping both owner columns also resolves a conflict cleanly: the sources
     * disagree on account deletion (game_sessions.host_user_id CASCADEs,
     * scored_games.created_by_user_id SET NULLs). Two columns means each keeps
     * its original rule and trivia sees no behaviour change.
     */
    private const OFFSET = 10000;

    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['online', 'scorekeeper']);

            // Source row id, for traceability during the cutover. Dropped by
            // the contract migration once the dependents are repointed.
            $table->unsignedBigInteger('legacy_id');

            $table->string('name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            // ---- online (was game_sessions) --------------------------------
            $table->foreignId('game_type_id')->nullable()->constrained('game_types')->cascadeOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->nullable();
            $table->json('settings')->nullable();
            $table->string('invite_code', 10)->nullable();
            $table->timestamp('completed_at')->nullable();

            // ---- scorekeeper (was scored_games) ----------------------------
            $table->foreignId('household_id')->nullable()->constrained('households')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('game_template_id')->nullable()->constrained('game_templates')->nullOnDelete();
            $table->string('template_name_snapshot')->nullable();
            $table->string('base_game_type')->nullable();
            $table->integer('target_score')->nullable();
            $table->boolean('low_score_wins')->default(false);
            $table->integer('max_rounds')->nullable();
            $table->json('score_fields')->nullable();
            $table->boolean('team_based')->default(false);
            $table->boolean('allow_self_scoring')->default(false);
            $table->boolean('is_complete')->default(false);
            $table->timestamp('ended_at')->nullable();

            $table->unique(['kind', 'legacy_id']);
            $table->index('status');
            $table->index('invite_code');
            $table->index(['household_id', 'is_complete']);
        });

        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_id');

            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->unsignedSmallInteger('display_order');
            $table->integer('total_score')->default(0);
            $table->timestamps();

            // scored_game_competitors already enforces this; teams does not, but
            // its data satisfies it. Keeping the stronger invariant — the trivia
            // reorder is switched to the two-pass parking write to match.
            $table->unique(['game_id', 'display_order']);
        });

        $this->backfillGames();
        $this->backfillCompetitors();
        $this->resetAutoIncrements();
    }

    private function backfillGames(): void
    {
        foreach (DB::table('game_sessions')->orderBy('id')->cursor() as $s) {
            DB::table('games')->insert([
                'id'           => $s->id,
                'kind'         => 'online',
                'legacy_id'    => $s->id,
                'name'         => $s->name,
                'started_at'   => $s->started_at,
                'game_type_id' => $s->game_type_id,
                'host_user_id' => $s->host_user_id,
                'status'       => $s->status,
                'settings'     => $s->settings,
                'invite_code'  => $s->invite_code,
                'completed_at' => $s->completed_at,
                'created_at'   => $s->created_at,
                'updated_at'   => $s->updated_at,
            ]);
        }

        foreach (DB::table('scored_games')->orderBy('id')->cursor() as $g) {
            DB::table('games')->insert([
                'id'                     => $g->id + self::OFFSET,
                'kind'                   => 'scorekeeper',
                'legacy_id'              => $g->id,
                'name'                   => $g->template_name_snapshot,
                'started_at'             => $g->started_at,
                'household_id'           => $g->household_id,
                'created_by_user_id'     => $g->created_by_user_id,
                'game_template_id'       => $g->game_template_id,
                'template_name_snapshot' => $g->template_name_snapshot,
                'base_game_type'         => $g->base_game_type,
                'target_score'           => $g->target_score,
                'low_score_wins'         => $g->low_score_wins,
                'max_rounds'             => $g->max_rounds,
                'score_fields'           => $g->score_fields,
                'team_based'             => $g->team_based,
                'allow_self_scoring'     => $g->allow_self_scoring,
                'is_complete'            => $g->is_complete,
                'ended_at'               => $g->ended_at,
                'created_at'             => $g->created_at,
                'updated_at'             => $g->updated_at,
            ]);
        }
    }

    private function backfillCompetitors(): void
    {
        foreach (DB::table('teams')->orderBy('id')->cursor() as $t) {
            DB::table('competitors')->insert([
                'id'            => $t->id,
                'game_id'       => $t->game_session_id,
                'legacy_id'     => $t->id,
                'name'          => $t->name,
                'color'         => $t->color,
                'display_order' => $t->display_order,
                'total_score'   => $t->total_score,
                'created_at'    => $t->created_at,
                'updated_at'    => $t->updated_at,
            ]);
        }

        foreach (DB::table('scored_game_competitors')->orderBy('id')->cursor() as $c) {
            DB::table('competitors')->insert([
                'id'            => $c->id + self::OFFSET,
                'game_id'       => $c->scored_game_id + self::OFFSET,
                'legacy_id'     => $c->id,
                'name'          => $c->name,
                'color'         => null,
                'display_order' => $c->display_order,
                'total_score'   => 0,
                'created_at'    => $c->created_at,
                'updated_at'    => $c->updated_at,
            ]);
        }
    }

    /**
     * Explicit ids were inserted, so nudge the counters past the highest value
     * or the first natural insert collides.
     *
     * MySQL only: SQLite (which the test suite runs on) tracks the high-water
     * mark in sqlite_sequence and updates it on explicit-id inserts by itself,
     * and its ALTER TABLE has no AUTO_INCREMENT clause at all.
     */
    private function resetAutoIncrements(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['games', 'competitors'] as $table) {
            $max = (int) DB::table($table)->max('id');
            if ($max === 0) {
                continue;
            }
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = " . ($max + 1));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
        Schema::dropIfExists('games');
    }
};
