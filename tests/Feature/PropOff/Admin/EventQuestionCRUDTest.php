<?php

namespace Tests\Feature\PropOff\Admin;

use App\Models\PropOff\Event;
use App\Models\PropOff\EventQuestion;
use App\Models\PropOff\Group;
use App\Models\PropOff\GroupQuestion;
use App\Models\PropOff\QuestionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventQuestionCRUDTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function admin_can_create_custom_question()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);
        $group = Group::factory()->create(['event_id' => $event->id]);

        $questionData = [
            'question_text' => 'Who will win the game?',
            'question_type' => 'multiple_choice',
            'options' => [
                ['label' => 'Team A', 'points' => 0],
                ['label' => 'Team B', 'points' => 0],
            ],
            'points' => 10,
            'order' => 1,
        ];

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.store', $event),
            $questionData
        );

        // Question management moved onto the event page; the standalone index is gone.
        $response->assertRedirect(route('propoff.admin.events.show', $event));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('propoff_event_questions', [
            'event_id' => $event->id,
            'question_text' => 'Who will win the game?',
            'question_type' => 'multiple_choice',
            'points' => 10,
            'display_order' => 1,
        ]);

        // Verify group question was created
        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group->id,
            'question_text' => 'Who will win the game?',
            'is_custom' => false,
        ]);
    }

    /** @test */
    public function admin_can_create_question_from_template()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);
        $group = Group::factory()->create(['event_id' => $event->id]);

        $template = QuestionTemplate::factory()->create([
            'question_text' => 'Who will win {team1} vs {team2}?',
            'variables' => ['team1', 'team2'],
            'question_type' => 'text',
            'default_points' => 5,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.createFromTemplate', [$event, $template]),
            [
                'variable_values' => [
                    'team1' => 'Lakers',
                    'team2' => 'Celtics',
                ],
                'order' => 1,
                'points' => 10,
            ]
        );

        // Question management moved onto the event page; the standalone index is gone.
        $response->assertRedirect(route('propoff.admin.events.show', $event));

        $this->assertDatabaseHas('propoff_event_questions', [
            'event_id' => $event->id,
            'question_text' => 'Who will win Lakers vs Celtics?',
            'template_id' => $template->id,
            'points' => 10,
        ]);
    }

    /** @test */
    public function admin_can_update_question()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);
        $group = Group::factory()->create(['event_id' => $event->id]);

        $question = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'Original question',
            'points' => 5,
            'display_order' => 1,
        ]);

        // Create corresponding group question
        GroupQuestion::factory()->create([
            'group_id' => $group->id,
            'event_question_id' => $question->id,
            'question_text' => 'Original question',
            'points' => 5,
            'is_custom' => false,
        ]);

        $updateData = [
            'question_text' => 'Updated question',
            'question_type' => 'text',
            'options' => null,
            'points' => 15,
            // The controller requires display_order; the test predated that and
            // was sending 'order', which the validator ignores.
            'display_order' => 1,
        ];

        $response = $this->actingAs($admin)->patch(
            route('propoff.admin.events.event-questions.update', [$event, $question]),
            $updateData
        );

        // Question management moved onto the event page; the standalone index is gone.
        $response->assertRedirect(route('propoff.admin.events.show', $event));

        $this->assertDatabaseHas('propoff_event_questions', [
            'id' => $question->id,
            'question_text' => 'Updated question',
            'points' => 15,
        ]);

        // Verify group question was updated
        $this->assertDatabaseHas('propoff_group_questions', [
            'event_question_id' => $question->id,
            'question_text' => 'Updated question',
            'points' => 15,
        ]);
    }

    /** @test */
    public function admin_can_delete_question()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);
        $question = EventQuestion::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->delete(
            route('propoff.admin.events.event-questions.destroy', [$event, $question])
        );

        // Question management moved onto the event page; the standalone index is gone.
        $response->assertRedirect(route('propoff.admin.events.show', $event));
        $this->assertDatabaseMissing('propoff_event_questions', ['id' => $question->id]);
    }

    /** @test */
    public function admin_can_reorder_questions()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);
        $group = Group::factory()->create(['event_id' => $event->id]);

        $question1 = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'display_order' => 1,
        ]);
        $question2 = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'display_order' => 2,
        ]);

        GroupQuestion::factory()->create([
            'group_id' => $group->id,
            'event_question_id' => $question1->id,
            'display_order' => 1,
            'is_custom' => false,
        ]);
        GroupQuestion::factory()->create([
            'group_id' => $group->id,
            'event_question_id' => $question2->id,
            'display_order' => 2,
            'is_custom' => false,
        ]);

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.reorder', $event),
            [
                'event_questions' => [
                    ['id' => $question1->id, 'order' => 2],
                    ['id' => $question2->id, 'order' => 1],
                ]
            ]
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('propoff_event_questions', [
            'id' => $question1->id,
            'display_order' => 2,
        ]);
        $this->assertDatabaseHas('propoff_event_questions', [
            'id' => $question2->id,
            'display_order' => 1,
        ]);

        // Verify group questions were reordered
        $this->assertDatabaseHas('propoff_group_questions', [
            'event_question_id' => $question1->id,
            'display_order' => 2,
        ]);
        $this->assertDatabaseHas('propoff_group_questions', [
            'event_question_id' => $question2->id,
            'display_order' => 1,
        ]);
    }

    /** @test */
    public function admin_can_duplicate_question()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);
        $group = Group::factory()->create(['event_id' => $event->id]);

        $question = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'Original question',
            'display_order' => 1,
        ]);

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.duplicate', [$event, $question])
        );

        $response->assertRedirect();

        $this->assertEquals(2, EventQuestion::where('event_id', $event->id)->count());
        $this->assertEquals(1, EventQuestion::where('question_text', 'Original question')
            ->where('display_order', 2)->count());
    }

    /** @test */
    public function non_admin_cannot_create_question()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($user)->post(
            route('propoff.admin.events.event-questions.store', $event),
            [
                'question_text' => 'Test question',
                'question_type' => 'text',
                'points' => 10,
                'order' => 1,
            ]
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_update_question()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $question = EventQuestion::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->patch(
            route('propoff.admin.events.event-questions.update', [$event, $question]),
            [
                'question_text' => 'Updated',
                'question_type' => 'text',
                'points' => 10,
                'display_order' => 1,
            ]
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_delete_question()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $question = EventQuestion::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)->delete(
            route('propoff.admin.events.event-questions.destroy', [$event, $question])
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function question_creation_requires_valid_question_type()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.store', $event),
            [
                'question_text' => 'Test question',
                'question_type' => 'invalid_type',
                'points' => 10,
                'order' => 1,
            ]
        );

        $response->assertSessionHasErrors('question_type');
    }

    /** @test */
    public function question_creation_creates_group_questions_for_all_groups()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);

        $group1 = Group::factory()->create(['event_id' => $event->id]);
        $group2 = Group::factory()->create(['event_id' => $event->id]);
        $group3 = Group::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.store', $event),
            [
                'question_text' => 'Test question',
                'question_type' => 'text',
                'points' => 10,
                'order' => 1,
            ]
        );

        $response->assertRedirect();

        $eventQuestion = EventQuestion::where('event_id', $event->id)->first();

        $this->assertEquals(3, GroupQuestion::where('event_question_id', $eventQuestion->id)->count());
        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group1->id,
            'event_question_id' => $eventQuestion->id,
        ]);
        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group2->id,
            'event_question_id' => $eventQuestion->id,
        ]);
        $this->assertDatabaseHas('propoff_group_questions', [
            'group_id' => $group3->id,
            'event_question_id' => $eventQuestion->id,
        ]);
    }

    /** @test */
    public function updating_question_updates_non_custom_group_questions()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);
        $group = Group::factory()->create(['event_id' => $event->id]);

        $question = EventQuestion::factory()->create([
            'event_id' => $event->id,
            'question_text' => 'Original',
            'points' => 5,
        ]);

        // Create non-custom group question (should be updated)
        $groupQuestion1 = GroupQuestion::factory()->create([
            'group_id' => $group->id,
            'event_question_id' => $question->id,
            'question_text' => 'Original',
            'is_custom' => false,
        ]);

        // Create custom group question (should NOT be updated)
        $groupQuestion2 = GroupQuestion::factory()->create([
            'group_id' => $group->id,
            'event_question_id' => $question->id,
            'question_text' => 'Custom version',
            'is_custom' => true,
        ]);

        $this->actingAs($admin)->patch(
            route('propoff.admin.events.event-questions.update', [$event, $question]),
            [
                'question_text' => 'Updated',
                'question_type' => 'text',
                'points' => 10,
                'display_order' => 1,
            ]
        );

        $this->assertDatabaseHas('propoff_group_questions', [
            'id' => $groupQuestion1->id,
            'question_text' => 'Updated',
        ]);

        $this->assertDatabaseHas('propoff_group_questions', [
            'id' => $groupQuestion2->id,
            'question_text' => 'Custom version', // Should remain unchanged
        ]);
    }
}
