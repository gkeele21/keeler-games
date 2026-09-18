<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2, cutover step: move every dependent foreign key off the four
     * legacy tables and onto `games` / `competitors`, so the models can be
     * repointed in the same change.
     *
     * Column names are deliberately left alone — game_states.game_session_id
     * keeps its name while now referencing `games`. Renaming dependents is a
     * separate cosmetic pass; bundling it here would add churn to the one
     * migration that actually moves live data.
     *
     * Because the expand step preserved online ids, only three tables need
     * their values remapped (the scorekeeper side, +OFFSET). Everything else
     * is a pure constraint swap with the data untouched:
     *
     *   remap + reconstrain   rounds, competitor_player, round_scores
     *   reconstrain only      game_session_players, game_states, session_cards,
     *                         session_players, session_questions, answer_reveals
     *
     * The legacy tables themselves (game_sessions, scored_games, teams,
     * scored_game_competitors) are left in place and become stale copies. The
     * contract migration drops them once this has been exercised in anger.
     */
    private const OFFSET = 10000;

    /**
     * [table, column, new target, delete rule] — the pure constraint swaps.
     *
     * The delete rule is carried explicitly and matches the source constraint
     * exactly. Four of these are SET NULL rather than CASCADE, and the
     * difference matters: answer_reveals holds the per-team scoring history, so
     * cascading it on competitor delete would destroy score records that today
     * merely lose their team reference.
     */
    private const RECONSTRAIN = [
        ['game_session_players', 'game_session_id',     'games',       'cascade'],
        ['game_states',          'game_session_id',     'games',       'cascade'],
        ['session_cards',        'game_session_id',     'games',       'cascade'],
        ['session_players',      'game_session_id',     'games',       'cascade'],
        ['session_questions',    'game_session_id',     'games',       'cascade'],
        ['team_members',         'team_id',             'competitors', 'cascade'],
        ['game_states',          'active_team_id',      'competitors', 'set null'],
        ['session_players',      'team_id',             'competitors', 'set null'],
        ['session_questions',    'controlling_team_id', 'competitors', 'set null'],
        ['answer_reveals',       'team_id',             'competitors', 'set null'],
    ];

    /** [table, column, new target, delete rule] — need a +OFFSET remap first. */
    private const REMAP = [
        ['rounds',            'scored_game_id', 'games',       'cascade'],
        ['competitor_player', 'competitor_id',  'competitors', 'cascade'],
        ['round_scores',      'competitor_id',  'competitors', 'cascade'],
    ];

    public function up(): void
    {
        // New rows written by the app have no legacy counterpart.
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->change();
        });
        Schema::table('competitors', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->change();
        });

        foreach (self::RECONSTRAIN as [$table, $column, $target, $rule]) {
            $this->dropForeignKey($table, $column);
            $this->addForeignKey($table, $column, $target, $rule);
        }

        foreach (self::REMAP as [$table, $column, $target, $rule]) {
            $this->dropForeignKey($table, $column);
            // Safe as a single statement: every current value is far below
            // OFFSET, so the shifted range cannot overlap the unshifted one.
            DB::table($table)->whereNotNull($column)->update([
                $column => DB::raw("`{$column}` + " . self::OFFSET),
            ]);
            $this->addForeignKey($table, $column, $target, $rule);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::REMAP) as [$table, $column, $target, $rule]) {
            $this->dropForeignKey($table, $column);
            DB::table($table)->whereNotNull($column)->update([
                $column => DB::raw("`{$column}` - " . self::OFFSET),
            ]);
            $this->addForeignKey($table, $column, $this->legacyTargetFor($target, $column), $rule);
        }

        foreach (array_reverse(self::RECONSTRAIN) as [$table, $column, $target, $rule]) {
            $this->dropForeignKey($table, $column);
            $this->addForeignKey($table, $column, $this->legacyTargetFor($target, $column), $rule);
        }

        Schema::table('competitors', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable(false)->change();
        });
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable(false)->change();
        });
    }

    private function legacyTargetFor(string $target, string $column): string
    {
        if ($target === 'competitors') {
            return $column === 'competitor_id' ? 'scored_game_competitors' : 'teams';
        }

        return $column === 'scored_game_id' ? 'scored_games' : 'game_sessions';
    }

    private function dropForeignKey(string $table, string $column): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->dropForeign([$column]);
        });
    }

    private function addForeignKey(string $table, string $column, string $target, string $rule): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $target, $rule) {
            $foreign = $blueprint->foreign($column)->references('id')->on($target);

            $rule === 'set null'
                ? $foreign->nullOnDelete()
                : $foreign->cascadeOnDelete();
        });
    }
};
