<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which guest is which person is a human judgement, so the decisions are
 * recorded once and replayed per environment rather than re-made each time.
 * That is only safe if replaying is idempotent and refuses to act when the ids
 * no longer mean what they meant when the call was made.
 */
class MergeDecisionFileTest extends TestCase
{
    use RefreshDatabase;

    private function decisionFile(array $decisions): string
    {
        $path = sys_get_temp_dir() . '/merges-' . uniqid() . '.json';
        file_put_contents($path, json_encode($decisions));

        return $path;
    }

    public function test_it_applies_the_recorded_decisions(): void
    {
        $guest = User::factory()->create(['first_name' => 'Krista', 'last_name' => '', 'role' => 'guest']);
        $real = User::factory()->create(['first_name' => 'Krista', 'last_name' => 'Keele', 'role' => 'user']);

        $file = $this->decisionFile([[
            'source' => $guest->id, 'target' => $real->id,
            'source_name' => 'Krista', 'target_name' => 'Krista Keele',
        ]]);

        $this->artisan("propoff:merge-guests --from={$file}")->assertSuccessful();

        $this->assertNull(User::find($guest->id));
        $this->assertNotNull(User::find($real->id));
    }

    public function test_re_running_is_a_no_op(): void
    {
        $guest = User::factory()->create(['first_name' => 'Krista', 'last_name' => '', 'role' => 'guest']);
        $real = User::factory()->create(['first_name' => 'Krista', 'last_name' => 'Keele', 'role' => 'user']);

        $file = $this->decisionFile([[
            'source' => $guest->id, 'target' => $real->id,
            'source_name' => 'Krista', 'target_name' => 'Krista Keele',
        ]]);

        $this->artisan("propoff:merge-guests --from={$file}")->assertSuccessful();

        // Deploying the same file again must not fail or touch anything.
        $this->artisan("propoff:merge-guests --from={$file}")
            ->expectsOutputToContain('already merged')
            ->assertSuccessful();

        $this->assertSame(1, User::where('first_name', 'Krista')->count());
    }

    public function test_it_refuses_when_the_ids_no_longer_match_the_names(): void
    {
        $guest = User::factory()->create(['first_name' => 'Krista', 'last_name' => '', 'role' => 'guest']);
        $other = User::factory()->create(['first_name' => 'Someone', 'last_name' => 'Else', 'role' => 'user']);

        // The decision was made against different data — ids have drifted, and
        // acting on them would merge two strangers.
        $file = $this->decisionFile([[
            'source' => $guest->id, 'target' => $other->id,
            'source_name' => 'Krista', 'target_name' => 'Krista Keele',
        ]]);

        $this->artisan("propoff:merge-guests --from={$file}")->assertFailed();

        $this->assertNotNull(User::find($guest->id));
        $this->assertNotNull(User::find($other->id));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $guest = User::factory()->create(['first_name' => 'Krista', 'last_name' => '', 'role' => 'guest']);
        $real = User::factory()->create(['first_name' => 'Krista', 'last_name' => 'Keele', 'role' => 'user']);

        $file = $this->decisionFile([[
            'source' => $guest->id, 'target' => $real->id,
            'source_name' => 'Krista', 'target_name' => 'Krista Keele',
        ]]);

        $this->artisan("propoff:merge-guests --from={$file} --dry-run")->assertSuccessful();

        $this->assertNotNull(User::find($guest->id));
    }

    public function test_the_committed_decision_file_is_valid(): void
    {
        $path = database_path('merges/propoff-guest-merges.json');
        $this->assertFileExists($path);

        $decisions = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decisions, 'decision file must be a JSON array');

        foreach ($decisions as $i => $d) {
            foreach (['source', 'target', 'source_name', 'target_name'] as $key) {
                $this->assertArrayHasKey($key, $d, "entry {$i} is missing {$key}");
            }
            $this->assertNotSame($d['source'], $d['target'], "entry {$i} merges someone into themselves");
        }
    }
}
