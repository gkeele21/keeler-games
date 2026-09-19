<?php

namespace Tests\Feature\PropOff;

use App\Models\PropOff\Event;
use App\Models\PropOff\EventQuestion;
use App\Models\PropOff\Group;
use App\Models\PropOff\GroupQuestion;
use App\Models\PropOff\QuestionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsolidatedVariableImportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function can_import_multiple_templates_with_shared_variables()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);
        $group = Group::factory()->create(['event_id' => $event->id]);

        $template1 = QuestionTemplate::factory()->create([
            'question_text' => 'Who will win {team1} vs {team2}?',
            'variables' => ['team1', 'team2'],
            'question_type' => 'text',
            'created_by' => $admin->id,
        ]);

        $template2 = QuestionTemplate::factory()->create([
            'question_text' => 'Will {team1} score over 100 points?',
            'variables' => ['team1'],
            'question_type' => 'yes_no',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.bulkCreateFromTemplates', $event),
            [
                'templates' => [
                    [
                        'template_id' => $template1->id,
                        'variable_values' => [
                            'team1' => 'Lakers',
                            'team2' => 'Celtics'
                        ]
                    ],
                    [
                        'template_id' => $template2->id,
                        'variable_values' => [
                            'team1' => 'Lakers'
                        ]
                    ]
                ]
            ]
        );

        // Question management moved onto the event page; the standalone index is gone.
        $response->assertRedirect(route('propoff.admin.events.show', $event));

        $this->assertDatabaseHas('propoff_event_questions', [
            'event_id' => $event->id,
            'question_text' => 'Who will win Lakers vs Celtics?'
        ]);

        $this->assertDatabaseHas('propoff_event_questions', [
            'event_id' => $event->id,
            'question_text' => 'Will Lakers score over 100 points?'
        ]);

        // Verify group questions were created
        $this->assertEquals(2, GroupQuestion::where('group_id', $group->id)->count());
    }

    /** @test */
    public function variables_are_replaced_in_options()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);

        $template = QuestionTemplate::factory()->create([
            'question_text' => 'Who will win?',
            'variables' => ['team1', 'team2'],
            'question_type' => 'multiple_choice',
            'default_options' => [
                ['label' => '{team1}', 'points' => 0],
                ['label' => '{team2}', 'points' => 0],
                ['label' => 'Draw', 'points' => 0]
            ],
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.bulkCreateFromTemplates', $event),
            [
                'templates' => [
                    [
                        'template_id' => $template->id,
                        'variable_values' => [
                            'team1' => 'Lakers',
                            'team2' => 'Celtics'
                        ]
                    ]
                ]
            ]
        );

        $question = EventQuestion::where('event_id', $event->id)->first();

        $this->assertEquals('Lakers', $question->options[0]['label']);
        $this->assertEquals('Celtics', $question->options[1]['label']);
        $this->assertEquals('Draw', $question->options[2]['label']);
    }

    /** @test */
    public function can_import_templates_without_variables()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);

        $template = QuestionTemplate::factory()->create([
            'question_text' => 'Will it rain tomorrow?',
            'variables' => null,
            'question_type' => 'yes_no',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.bulkCreateFromTemplates', $event),
            [
                'templates' => [
                    [
                        'template_id' => $template->id,
                        'variable_values' => []
                    ]
                ]
            ]
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('propoff_event_questions', [
            'event_id' => $event->id,
            'question_text' => 'Will it rain tomorrow?'
        ]);
    }

    /** @test */
    public function imports_maintain_template_reference()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);

        $template = QuestionTemplate::factory()->create([
            'question_text' => 'Who will win {team}?',
            'variables' => ['team'],
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.bulkCreateFromTemplates', $event),
            [
                'templates' => [
                    [
                        'template_id' => $template->id,
                        'variable_values' => ['team' => 'Lakers']
                    ]
                ]
            ]
        );

        $question = EventQuestion::where('event_id', $event->id)->first();

        $this->assertEquals($template->id, $question->template_id);
    }

    /** @test */
    public function multiple_variables_are_all_replaced()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);

        $template = QuestionTemplate::factory()->create([
            'question_text' => 'In {year}, will {team1} beat {team2} at {stadium}?',
            'variables' => ['year', 'team1', 'team2', 'stadium'],
            'question_type' => 'yes_no',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.bulkCreateFromTemplates', $event),
            [
                'templates' => [
                    [
                        'template_id' => $template->id,
                        'variable_values' => [
                            'year' => '2025',
                            'team1' => 'Chiefs',
                            'team2' => '49ers',
                            'stadium' => 'Allegiant Stadium'
                        ]
                    ]
                ]
            ]
        );

        $question = EventQuestion::where('event_id', $event->id)->first();

        $this->assertEquals(
            'In 2025, will Chiefs beat 49ers at Allegiant Stadium?',
            $question->question_text
        );
    }

    /** @test */
    public function same_variable_used_multiple_times_in_question()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);

        $template = QuestionTemplate::factory()->create([
            'question_text' => 'Will {team} beat {team} in overtime?',
            'variables' => ['team'],
            'question_type' => 'yes_no',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.bulkCreateFromTemplates', $event),
            [
                'templates' => [
                    [
                        'template_id' => $template->id,
                        'variable_values' => ['team' => 'Lakers']
                    ]
                ]
            ]
        );

        $question = EventQuestion::where('event_id', $event->id)->first();

        $this->assertEquals(
            'Will Lakers beat Lakers in overtime?',
            $question->question_text
        );
    }

    /** @test */
    public function only_admin_can_bulk_import_templates()
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $template = QuestionTemplate::factory()->create();

        $response = $this->actingAs($user)->post(
            route('propoff.admin.events.event-questions.bulkCreateFromTemplates', $event),
            [
                'templates' => [
                    [
                        'template_id' => $template->id,
                        'variable_values' => []
                    ]
                ]
            ]
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function group_questions_created_for_all_groups_in_event()
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->create(['created_by' => $admin->id]);

        $group1 = Group::factory()->create(['event_id' => $event->id]);
        $group2 = Group::factory()->create(['event_id' => $event->id]);
        $group3 = Group::factory()->create(['event_id' => $event->id]);

        $template = QuestionTemplate::factory()->create([
            'question_text' => 'Test question?',
            'question_type' => 'yes_no',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(
            route('propoff.admin.events.event-questions.bulkCreateFromTemplates', $event),
            [
                'templates' => [
                    [
                        'template_id' => $template->id,
                        'variable_values' => []
                    ]
                ]
            ]
        );

        // Should create 1 EventQuestion
        $this->assertEquals(1, EventQuestion::where('event_id', $event->id)->count());

        // Should create 3 GroupQuestions (one per group)
        $this->assertEquals(1, GroupQuestion::where('group_id', $group1->id)->count());
        $this->assertEquals(1, GroupQuestion::where('group_id', $group2->id)->count());
        $this->assertEquals(1, GroupQuestion::where('group_id', $group3->id)->count());
    }
}
