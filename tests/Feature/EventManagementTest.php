<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Enums\ScoringSystem;
use App\Enums\TieRankingMethod;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_events(): void
    {
        $this->get('/events')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_events_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => AccountRole::Admin]))
            ->get('/events')
            ->assertOk()
            ->assertSee('Add event')
            ->assertSee('Scoring system');
    }

    public function test_authenticated_user_can_create_an_event(): void
    {
        $user = User::factory()->create(['role' => AccountRole::Admin]);

        $response = $this->actingAs($user)->post('/events', [
            'event_name' => 'Regional Championship',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
            'scoring_system' => ScoringSystem::Points->value,
        ]);

        $event = Event::query()->sole();

        $response->assertRedirect(route('events.show', $event));

        $this->assertDatabaseHas('events', [
            'creator_id' => $user->id,
            'name' => 'Regional Championship',
            'scoring_system' => ScoringSystem::Points->value,
            'tie_ranking_method' => TieRankingMethod::SkipPositions->value,
            'leaderboard_frozen' => false,
        ]);
    }

    public function test_admin_can_edit_event_name_and_dates_from_the_configuration_page(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create([
            'name' => 'Original Event',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
        ]);

        $this->actingAs($admin)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('id="event-details-dialog"', escape: false)
            ->assertSee('value="Original Event"', escape: false)
            ->assertSee('value="2026-10-01"', escape: false);

        $this->actingAs($admin)
            ->patch(route('events.details.update', $event), [
                'event_name' => 'Updated Event',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-07',
            ])
            ->assertRedirect(route('events.show', $event));

        $event->refresh();
        $this->assertSame('Updated Event', $event->name);
        $this->assertSame('2026-10-05', $event->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-07', $event->end_date->format('Y-m-d'));
    }

    public function test_event_detail_changes_are_admin_only_and_dates_are_validated(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create(['name' => 'Unchanged Event']);

        $this->actingAs($auditor)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertDontSee('id="event-details-dialog"', escape: false);

        $this->actingAs($auditor)
            ->patch(route('events.details.update', $event), [
                'event_name' => 'Unauthorized',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-07',
            ])->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('events.details.update', $event), [
                'event_name' => 'Invalid Event',
                'start_date' => '2026-10-07',
                'end_date' => '2026-10-05',
            ])->assertSessionHasErrors('end_date');

        $this->assertSame('Unchanged Event', $event->refresh()->name);
    }

    public function test_auditor_can_configure_the_event_tie_rule(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create();

        $this->actingAs($auditor)
            ->patch(route('events.tie-ranking.update', $event), [
                'tie_ranking_method' => TieRankingMethod::ConsecutivePositions->value,
            ])
            ->assertRedirect(route('events.show', $event));

        $this->assertSame(TieRankingMethod::ConsecutivePositions, $event->refresh()->tie_ranking_method);

        $this->actingAs($auditor)
            ->patch(route('events.tie-ranking.update', $event), [
                'tie_ranking_method' => 'invalid',
            ])
            ->assertSessionHasErrors('tie_ranking_method');

        $this->assertSame(TieRankingMethod::ConsecutivePositions, $event->refresh()->tie_ranking_method);
    }

    public function test_other_scoring_system_requires_a_description(): void
    {
        $this->actingAs(User::factory()->create(['role' => AccountRole::Admin]))
            ->post('/events', [
                'event_name' => 'Special Event',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-03',
                'scoring_system' => ScoringSystem::Other->value,
            ])
            ->assertSessionHasErrors('other_scoring_system');
    }

    public function test_end_date_cannot_be_before_start_date(): void
    {
        $this->actingAs(User::factory()->create(['role' => AccountRole::Admin]))
            ->post('/events', [
                'event_name' => 'Invalid Event',
                'start_date' => '2026-10-03',
                'end_date' => '2026-10-01',
                'scoring_system' => ScoringSystem::Ranking->value,
            ])
            ->assertSessionHasErrors('end_date');
    }

    public function test_event_configuration_accepts_participants_categories_and_competitions(): void
    {
        $user = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->for($user, 'creator')->create();

        $this->actingAs($user)
            ->post(route('events.participants.store', $event), [
                'participant_name' => 'Jordan Reyes',
                'participant_reference' => 'P-001',
            ])
            ->assertRedirect(route('events.show', $event));

        $this->actingAs($user)
            ->post(route('events.categories.store', $event), [
                'category_name' => 'Senior Division',
            ])
            ->assertRedirect(route('events.show', $event));

        $category = Category::query()->sole();

        $this->actingAs($user)
            ->post(route('events.categories.competitions.store', [$event, $category]), [
                'competition_name' => 'Solo Performance',
            ])
            ->assertRedirect(route('events.show', $event));

        $this->assertDatabaseHas('participants', [
            'event_id' => $event->id,
            'name' => 'Jordan Reyes',
            'reference_no' => 'P-001',
        ]);
        $this->assertDatabaseHas('categories', [
            'event_id' => $event->id,
            'name' => 'Senior Division',
        ]);
        $this->assertDatabaseHas('competitions', [
            'category_id' => $category->id,
            'name' => 'Solo Performance',
        ]);

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Jordan Reyes')
            ->assertSee('Senior Division')
            ->assertSee('Solo Performance')
            ->assertSee('Current leaderboard')
            ->assertSee('Enter scores');
    }

    public function test_add_contest_dialog_creates_a_preconfigured_criteria_contest(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Senior Division']);

        $this->actingAs($admin)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('id="add-contest-dialog-'.$category->id.'"', escape: false)
            ->assertSee('Number of judges');

        $this->actingAs($admin)
            ->post(route('events.categories.competitions.store', [$event, $category]), [
                'competition_name' => 'Solo Performance',
                'scoring_method' => CompetitionScoringMethod::Criteria->value,
                'judge_count' => 3,
            ])->assertRedirect(route('events.show', $event));

        $this->assertDatabaseHas('competitions', [
            'category_id' => $category->id,
            'name' => 'Solo Performance',
            'scoring_method' => CompetitionScoringMethod::Criteria->value,
            'judge_count' => 3,
        ]);
    }

    public function test_wins_contest_uses_one_judge_and_rejects_invalid_preliminary_configuration(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $route = route('events.categories.competitions.store', [$event, $category]);

        $this->actingAs($admin)->post($route, [
            'competition_name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins->value,
            'judge_count' => 7,
        ])->assertRedirect(route('events.show', $event));

        $this->assertDatabaseHas('competitions', [
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins->value,
            'judge_count' => 1,
        ]);

        $this->actingAs($admin)->post($route, [
            'competition_name' => 'Invalid contest',
            'scoring_method' => 'unknown',
            'judge_count' => 11,
        ])->assertSessionHasErrors('scoring_method');

        $this->actingAs($admin)->post($route, [
            'competition_name' => 'Too many judges',
            'scoring_method' => CompetitionScoringMethod::Criteria->value,
            'judge_count' => 11,
        ])->assertSessionHasErrors('judge_count');

        $this->assertDatabaseMissing('competitions', ['name' => 'Invalid contest']);
        $this->assertDatabaseMissing('competitions', ['name' => 'Too many judges']);
    }

    public function test_category_from_another_event_cannot_receive_a_competition(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user, 'creator')->create();
        $otherEvent = Event::factory()->for($user, 'creator')->create();
        $otherCategory = $otherEvent->categories()->create(['name' => 'Other category']);

        $this->actingAs($user)
            ->post(route('events.categories.competitions.store', [$event, $otherCategory]), [
                'competition_name' => 'Invalid competition',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('competitions', [
            'name' => 'Invalid competition',
        ]);
    }

    public function test_leaderboard_can_be_frozen_and_unfrozen(): void
    {
        $user = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->for($user, 'creator')->create();

        $this->actingAs($user)
            ->patch(route('events.leaderboard-freeze', $event))
            ->assertRedirect(route('events.show', $event));

        $this->assertTrue($event->refresh()->leaderboard_frozen);

        $this->actingAs($user)
            ->patch(route('events.leaderboard-freeze', $event))
            ->assertRedirect(route('events.show', $event));

        $this->assertFalse($event->refresh()->leaderboard_frozen);
    }

    public function test_admin_can_delete_an_event_and_its_stored_participant_images(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->for($admin, 'creator')->create();
        $profilePath = "participants/{$event->id}/profile.jpg";
        Storage::disk('public')->put($profilePath, 'profile-image');

        $participant = $event->participants()->create([
            'name' => 'Jordan Reyes',
            'profile_picture_path' => $profilePath,
        ]);
        $category = $event->categories()->create(['name' => 'Senior Division']);
        $competition = $category->competitions()->create(['name' => 'Solo Performance']);

        $this->actingAs($admin)
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHas('status', 'Event deleted successfully.');

        $this->assertModelMissing($event);
        $this->assertModelMissing($participant);
        $this->assertModelMissing($category);
        $this->assertModelMissing($competition);
        Storage::disk('public')->assertMissing($profilePath);
    }

    public function test_auditor_cannot_delete_an_event(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create();

        $this->actingAs($auditor)
            ->delete(route('events.destroy', $event))
            ->assertForbidden();

        $this->assertModelExists($event);
    }
}
