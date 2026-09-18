<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merges propoff_captain_invitations and propoff_event_invitations into one
     * propoff_invitations table.
     *
     * They were copy-paste duplicates: same columns bar one, and identical
     * generateToken(), isValid() and incrementUsage() on both models. They model
     * a single concept — a shared join link for an event — and differ only in
     * what the link does:
     *
     *   group_id IS NULL    the holder creates a group and becomes its captain
     *   group_id IS NOT NULL the holder joins that specific group
     *
     * So group_id is the discriminator, not a reason for two tables.
     *
     * household_invites is deliberately NOT merged in. Despite the shared name
     * it is a different thing: a personal invite to one email address, accepted
     * once, carrying a role and a roster player. The PropOff links are open and
     * reusable, counted by max_uses/times_used. The two share only a token and
     * an expiry; folding them together would produce a table where each kind
     * nulls half the columns.
     *
     * Captain rows are offset by OFFSET to avoid colliding with event row ids.
     * Nothing has a foreign key into either table, so no dependents need
     * repointing — the ids only have to stay internally consistent.
     */
    private const OFFSET = 10000;

    public function up(): void
    {
        Schema::create('propoff_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('propoff_events')->cascadeOnDelete();

            // NULL = a captain link (create a group); set = a link into that group.
            $table->foreignId('group_id')->nullable()->constrained('propoff_groups')->cascadeOnDelete();

            $table->string('token', 64)->unique();
            $table->integer('max_uses')->nullable();
            $table->integer('times_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            // Only captain links recorded an author; kept nullable so both fit.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'group_id']);
        });

        foreach (DB::table('propoff_event_invitations')->orderBy('id')->cursor() as $i) {
            DB::table('propoff_invitations')->insert([
                'id'         => $i->id,
                'event_id'   => $i->event_id,
                'group_id'   => $i->group_id,
                'token'      => $i->token,
                'max_uses'   => $i->max_uses,
                'times_used' => $i->times_used,
                'expires_at' => $i->expires_at,
                'is_active'  => $i->is_active,
                'created_by' => null,
                'created_at' => $i->created_at,
                'updated_at' => $i->updated_at,
            ]);
        }

        foreach (DB::table('propoff_captain_invitations')->orderBy('id')->cursor() as $i) {
            DB::table('propoff_invitations')->insert([
                'id'         => $i->id + self::OFFSET,
                'event_id'   => $i->event_id,
                'group_id'   => null,
                'token'      => $i->token,
                'max_uses'   => $i->max_uses,
                'times_used' => $i->times_used,
                'expires_at' => $i->expires_at,
                'is_active'  => $i->is_active,
                'created_by' => $i->created_by,
                'created_at' => $i->created_at,
                'updated_at' => $i->updated_at,
            ]);
        }

        $this->bumpAutoIncrement();

        Schema::dropIfExists('propoff_captain_invitations');
        Schema::dropIfExists('propoff_event_invitations');
    }

    public function down(): void
    {
        Schema::create('propoff_captain_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('propoff_events')->cascadeOnDelete();
            $table->string('token', 32)->unique();
            $table->integer('max_uses')->nullable();
            $table->integer('times_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('propoff_event_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('propoff_events')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('propoff_groups')->cascadeOnDelete();
            $table->string('token', 32)->unique();
            $table->integer('max_uses')->nullable();
            $table->integer('times_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (DB::table('propoff_invitations')->orderBy('id')->cursor() as $i) {
            if ($i->group_id === null) {
                DB::table('propoff_captain_invitations')->insert([
                    'id'         => $i->id - self::OFFSET,
                    'event_id'   => $i->event_id,
                    'token'      => $i->token,
                    'max_uses'   => $i->max_uses,
                    'times_used' => $i->times_used,
                    'expires_at' => $i->expires_at,
                    'is_active'  => $i->is_active,
                    'created_by' => $i->created_by,
                    'created_at' => $i->created_at,
                    'updated_at' => $i->updated_at,
                ]);
                continue;
            }

            DB::table('propoff_event_invitations')->insert([
                'id'         => $i->id,
                'event_id'   => $i->event_id,
                'group_id'   => $i->group_id,
                'token'      => $i->token,
                'max_uses'   => $i->max_uses,
                'times_used' => $i->times_used,
                'expires_at' => $i->expires_at,
                'is_active'  => $i->is_active,
                'created_at' => $i->created_at,
                'updated_at' => $i->updated_at,
            ]);
        }

        Schema::dropIfExists('propoff_invitations');
    }

    /**
     * MySQL only: SQLite tracks the high-water mark itself on explicit-id
     * inserts and has no AUTO_INCREMENT clause on ALTER TABLE.
     */
    private function bumpAutoIncrement(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $max = (int) DB::table('propoff_invitations')->max('id');
        if ($max > 0) {
            DB::statement("ALTER TABLE `propoff_invitations` AUTO_INCREMENT = " . ($max + 1));
        }
    }
};
