<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2, contract step: drop the four tables `games` and `competitors`
     * replaced. They have been stale copies since the cutover — no code reads
     * them and the only foreign keys left pointed at each other.
     *
     * `legacy_id` is deliberately KEPT on both new tables. It costs two columns
     * and buys two things: it makes down() below a real restore rather than an
     * empty shell, and it leaves the door open to redirecting the old
     * /scorekeeper/.../games/{id} URLs, whose ids shifted by 10,000 at cutover.
     * Dropping it is a trivial follow-up once that call is made.
     *
     * down() rebuilds the original schema and repopulates it from games/
     * competitors by legacy_id, so a full revert is:
     *     migrate:rollback --step=2   (this, then the FK repoint)
     * in that order — the repoint's own down() needs these tables to exist.
     */
    public function up(): void
    {
        // Children first: each still holds a foreign key to its parent.
        Schema::dropIfExists('teams');
        Schema::dropIfExists('scored_game_competitors');
        Schema::dropIfExists('game_sessions');
        Schema::dropIfExists('scored_games');
    }

    public function down(): void
    {
        $this->recreateGameSessions();
        $this->recreateScoredGames();
        $this->recreateTeams();
        $this->recreateScoredGameCompetitors();
        $this->restoreData();
    }

    private function recreateGameSessions(): void
    {
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_type_id')->constrained('game_types')->cascadeOnDelete();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->enum('status', ['lobby', 'playing', 'paused', 'completed'])->default('lobby')->index();
            $table->json('settings')->nullable();
            $table->string('invite_code', 10)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    private function recreateScoredGames(): void
    {
        Schema::create('scored_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('game_template_id')->nullable()->constrained('game_templates')->nullOnDelete();
            $table->string('template_name_snapshot');
            $table->string('base_game_type')->nullable();
            $table->integer('target_score')->nullable();
            $table->boolean('low_score_wins')->default(false);
            $table->integer('max_rounds')->nullable();
            $table->json('score_fields')->nullable();
            $table->boolean('team_based')->default(false);
            $table->boolean('allow_self_scoring')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->boolean('is_complete')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('household_id');
            $table->index(['household_id', 'is_complete']);
        });
    }

    private function recreateTeams(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_session_id')->constrained('game_sessions')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7);
            $table->unsignedInteger('display_order')->default(0);
            $table->integer('total_score')->default(0);
            $table->timestamps();
            $table->index('game_session_id');
        });
    }

    private function recreateScoredGameCompetitors(): void
    {
        Schema::create('scored_game_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scored_game_id')->constrained('scored_games')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('display_order');
            $table->timestamps();
            $table->unique(['scored_game_id', 'display_order']);
        });
    }

    /**
     * Rebuild the legacy rows from the unified tables. legacy_id carries the
     * original primary key, so ids come back exactly as they were; rows created
     * after the cutover have no legacy_id and are skipped, since by definition
     * they never existed in the old tables.
     */
    private function restoreData(): void
    {
        foreach (DB::table('games')->where('kind', 'online')->whereNotNull('legacy_id')->orderBy('id')->cursor() as $g) {
            DB::table('game_sessions')->insert([
                'id'            => $g->legacy_id,
                'game_type_id'  => $g->game_type_id,
                'host_user_id'  => $g->host_user_id,
                'name'          => $g->name,
                'status'        => $g->status,
                'settings'      => $g->settings,
                'invite_code'   => $g->invite_code,
                'started_at'    => $g->started_at,
                'completed_at'  => $g->completed_at,
                'created_at'    => $g->created_at,
                'updated_at'    => $g->updated_at,
            ]);
        }

        foreach (DB::table('games')->where('kind', 'scorekeeper')->whereNotNull('legacy_id')->orderBy('id')->cursor() as $g) {
            DB::table('scored_games')->insert([
                'id'                     => $g->legacy_id,
                'household_id'           => $g->household_id,
                'game_template_id'       => $g->game_template_id,
                'template_name_snapshot' => $g->template_name_snapshot,
                'base_game_type'         => $g->base_game_type,
                'target_score'           => $g->target_score,
                'low_score_wins'         => $g->low_score_wins,
                'max_rounds'             => $g->max_rounds,
                'score_fields'           => $g->score_fields,
                'team_based'             => $g->team_based,
                'allow_self_scoring'     => $g->allow_self_scoring,
                'started_at'             => $g->started_at,
                'ended_at'               => $g->ended_at,
                'is_complete'            => $g->is_complete,
                'created_by_user_id'     => $g->created_by_user_id,
                'created_at'             => $g->created_at,
                'updated_at'             => $g->updated_at,
            ]);
        }

        foreach (DB::table('competitors as c')->join('games as g', 'g.id', '=', 'c.game_id')
            ->whereNotNull('c.legacy_id')->whereNotNull('g.legacy_id')
            ->orderBy('c.id')
            ->get(['c.*', 'g.kind as game_kind', 'g.legacy_id as game_legacy_id']) as $c) {
            if ($c->game_kind === 'online') {
                DB::table('teams')->insert([
                    'id'              => $c->legacy_id,
                    'game_session_id' => $c->game_legacy_id,
                    'name'            => $c->name,
                    'color'           => $c->color ?? '#3B82F6',
                    'display_order'   => $c->display_order,
                    'total_score'     => $c->total_score,
                    'created_at'      => $c->created_at,
                    'updated_at'      => $c->updated_at,
                ]);
                continue;
            }

            DB::table('scored_game_competitors')->insert([
                'id'             => $c->legacy_id,
                'scored_game_id' => $c->game_legacy_id,
                'name'           => $c->name,
                'display_order'  => $c->display_order,
                'created_at'     => $c->created_at,
                'updated_at'     => $c->updated_at,
            ]);
        }

        $this->resetAutoIncrements();
    }

    /**
     * MySQL only: SQLite maintains sqlite_sequence itself on explicit-id
     * inserts and has no AUTO_INCREMENT clause on ALTER TABLE.
     */
    private function resetAutoIncrements(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['game_sessions', 'scored_games', 'teams', 'scored_game_competitors'] as $table) {
            $max = (int) DB::table($table)->max('id');
            if ($max === 0) {
                continue;
            }
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = " . ($max + 1));
        }
    }
};
