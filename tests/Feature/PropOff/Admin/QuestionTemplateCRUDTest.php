<?php

namespace Tests\Feature\PropOff\Admin;

use App\Models\PropOff\QuestionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionTemplateCRUDTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function admin_can_view_templates_index()
    {
        $admin = User::factory()->manager()->create();
        QuestionTemplate::factory()->count(5)->create(['created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('propoff.admin.question-templates.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('PropOff/Admin/QuestionTemplates/Index')
                ->has('templates')
        );
    }

    /** @test */
    public function admin_can_view_create_template_form()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->get(route('propoff.admin.question-templates.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('PropOff/Admin/QuestionTemplates/TemplateForm')
        );
    }

    /** @test */
    public function admin_can_create_template_with_single_category()
    {
        $admin = User::factory()->manager()->create();

        $templateData = [
            'title' => 'Test Template',
            'category' => 'football',
            'question_text' => 'Who will win?',
            'question_type' => 'text',
            'default_points' => '10',
            'variables' => [],
        ];

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.question-templates.store'),
            $templateData
        );

        $response->assertRedirect(route('propoff.admin.question-templates.index'));

        $this->assertDatabaseHas('propoff_question_templates', [
            'title' => 'Test Template',
            'category' => 'football',
            'question_text' => 'Who will win?',
            'created_by' => $admin->id,
        ]);
    }

    /** @test */
    public function admin_can_create_template_with_multiple_categories()
    {
        $admin = User::factory()->manager()->create();

        $templateData = [
            'title' => 'Test Template',
            'category' => 'football,nfl,sports',
            'question_text' => 'Who will win?',
            'question_type' => 'text',
            'default_points' => '10',
            'variables' => [],
        ];

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.question-templates.store'),
            $templateData
        );

        $response->assertRedirect();

        $template = QuestionTemplate::where('title', 'Test Template')->first();
        $this->assertEquals('football,nfl,sports', $template->category);
        $this->assertEquals(['football', 'nfl', 'sports'], $template->categories);
    }

    /** @test */
    public function admin_can_create_template_with_variables()
    {
        $admin = User::factory()->manager()->create();

        $templateData = [
            'title' => 'Team vs Team',
            'category' => 'sports',
            'question_text' => 'Who will win {team1} vs {team2}?',
            'question_type' => 'text',
            'default_points' => '10',
            'variables' => ['team1', 'team2'],
        ];

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.question-templates.store'),
            $templateData
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('propoff_question_templates', [
            'title' => 'Team vs Team',
            'question_text' => 'Who will win {team1} vs {team2}?',
        ]);

        $template = QuestionTemplate::where('title', 'Team vs Team')->first();
        $this->assertEquals(['team1', 'team2'], $template->variables);
    }

    /** @test */
    public function admin_can_create_template_with_default_options()
    {
        $admin = User::factory()->manager()->create();

        $templateData = [
            'title' => 'Yes/No Template',
            'category' => 'general',
            'question_text' => 'Will it happen?',
            'question_type' => 'yes_no',
            'default_points' => '5',
            'variables' => [],
            'default_options' => [
                ['label' => 'Yes', 'points' => 0],
                ['label' => 'No', 'points' => 0],
            ],
        ];

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.question-templates.store'),
            $templateData
        );

        $response->assertRedirect();

        $template = QuestionTemplate::where('title', 'Yes/No Template')->first();
        $this->assertNotNull($template->default_options);
        $this->assertCount(2, $template->default_options);
    }

    /** @test */
    public function admin_can_view_edit_template_form()
    {
        $admin = User::factory()->manager()->create();
        $template = QuestionTemplate::factory()->create(['created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get(
            route('propoff.admin.question-templates.edit', $template)
        );

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('PropOff/Admin/QuestionTemplates/TemplateForm')
                ->has('template')
        );
    }

    /** @test */
    public function admin_can_update_template()
    {
        $admin = User::factory()->manager()->create();
        $template = QuestionTemplate::factory()->create([
            'created_by' => $admin->id,
            'title' => 'Original Title',
            'category' => 'original',
        ]);

        $updateData = [
            'title' => 'Updated Title',
            'category' => 'football,nfl',
            'question_text' => 'Updated question?',
            'question_type' => 'text',
            'default_points' => '15',
            'variables' => ['team'],
        ];

        $response = $this->actingAs($admin)->put(
            route('propoff.admin.question-templates.update', $template),
            $updateData
        );

        $response->assertRedirect(route('propoff.admin.question-templates.index'));

        $this->assertDatabaseHas('propoff_question_templates', [
            'id' => $template->id,
            'title' => 'Updated Title',
            'category' => 'football,nfl',
        ]);
    }

    /** @test */
    public function admin_can_delete_template()
    {
        $admin = User::factory()->manager()->create();
        $template = QuestionTemplate::factory()->create(['created_by' => $admin->id]);

        $response = $this->actingAs($admin)->delete(
            route('propoff.admin.question-templates.destroy', $template)
        );

        $response->assertRedirect(route('propoff.admin.question-templates.index'));
        $this->assertDatabaseMissing('propoff_question_templates', ['id' => $template->id]);
    }

    /** @test */
    public function non_admin_cannot_create_template()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('propoff.admin.question-templates.store'),
            [
                'title' => 'Test',
                'category' => 'test',
                'question_text' => 'Test?',
                'question_type' => 'text',
                'default_points' => '10',
            ]
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_update_template()
    {
        $admin = User::factory()->manager()->create();
        $user = User::factory()->create();
        $template = QuestionTemplate::factory()->create(['created_by' => $admin->id]);

        $response = $this->actingAs($user)->put(
            route('propoff.admin.question-templates.update', $template),
            [
                'title' => 'Updated',
                'category' => 'updated',
                'question_text' => 'Updated?',
                'question_type' => 'text',
                'default_points' => '10',
            ]
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function non_admin_cannot_delete_template()
    {
        $admin = User::factory()->manager()->create();
        $user = User::factory()->create();
        $template = QuestionTemplate::factory()->create(['created_by' => $admin->id]);

        $response = $this->actingAs($user)->delete(
            route('propoff.admin.question-templates.destroy', $template)
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function template_creation_requires_title()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.question-templates.store'),
            [
                'category' => 'test',
                'question_text' => 'Test?',
                'question_type' => 'text',
                'default_points' => '10',
            ]
        );

        $response->assertSessionHasErrors('title');
    }

    /** @test */
    public function template_creation_requires_valid_question_type()
    {
        $admin = User::factory()->manager()->create();

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.question-templates.store'),
            [
                'title' => 'Test',
                'category' => 'test',
                'question_text' => 'Test?',
                'question_type' => 'invalid_type',
                'default_points' => '10',
            ]
        );

        $response->assertSessionHasErrors('question_type');
    }

    /** @test */
    public function admin_can_filter_templates_by_category()
    {
        $admin = User::factory()->manager()->create();

        QuestionTemplate::factory()->create([
            'category' => 'football,nfl',
            'created_by' => $admin->id,
        ]);
        QuestionTemplate::factory()->create([
            'category' => 'basketball,nba',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(
            route('propoff.admin.question-templates.index', ['category' => 'football'])
        );

        $response->assertStatus(200);
    }

    /** @test */
    public function category_field_can_be_empty()
    {

        $admin = User::factory()->manager()->create();

        $templateData = [
            'title' => 'No Category Template',
            'question_text' => 'Generic question?',
            'question_type' => 'text',
            'default_points' => '10',
            'variables' => [],
            // category not included - should be nullable
        ];

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.question-templates.store'),
            $templateData
        );

        $response->assertRedirect();

        $template = QuestionTemplate::where('title', 'No Category Template')->first();
        $this->assertTrue(empty($template->category));
        $this->assertEquals([], $template->categories);
    }

    /** @test */
    public function template_with_variables_in_options()
    {
        $admin = User::factory()->manager()->create();

        $templateData = [
            'title' => 'Variable Options',
            'category' => 'sports',
            'question_text' => 'Who will win?',
            'question_type' => 'multiple_choice',
            'default_points' => '10',
            'variables' => ['team1', 'team2'],
            'default_options' => [
                ['label' => '{team1}', 'points' => 0],
                ['label' => '{team2}', 'points' => 0],
                ['label' => 'Draw', 'points' => 0],
            ],
        ];

        $response = $this->actingAs($admin)->post(
            route('propoff.admin.question-templates.store'),
            $templateData
        );

        $response->assertRedirect();

        $template = QuestionTemplate::where('title', 'Variable Options')->first();
        $this->assertStringContainsString('{team1}', $template->default_options[0]['label']);
    }
}
