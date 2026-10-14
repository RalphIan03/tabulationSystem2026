<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CompetitionScoringMethod;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PublicLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_url_opens_the_most_recent_active_event_in_overall_view(): void
    {
        Event::factory()->create([
            'name' => 'Older Active Event',
            'start_date' => today()->subDays(5),
            'end_date' => today()->addDays(5),
        ]);
        Event::factory()->create([
            'name' => 'Upcoming Event',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(10),
        ]);
        Event::factory()->create([
            'name' => 'Current Active Event',
            'start_date' => today()->subDay(),
            'end_date' => today()->addDay(),
        ]);

        $this->get(route('leaderboards.index'))
            ->assertOk()
            ->assertSee('Current Active Event')
            ->assertDontSee('Older Active Event')
            ->assertDontSee('Upcoming Event')
            ->assertSee('value="overall"', false);
    }

    public function test_bare_url_falls_back_to_latest_event_and_handles_no_events(): void
    {
        $this->get(route('leaderboards.index'))
            ->assertOk()
            ->assertSee('No events are available yet.');

        Event::factory()->create([
            'name' => 'Last Event',
            'start_date' => today()->subDays(10),
            'end_date' => today()->subDays(5),
        ]);
        Event::factory()->create([
            'name' => 'Recent Event',
            'start_date' => today()->subDays(3),
            'end_date' => today()->subDay(),
        ]);

        $this->get(route('leaderboards.index'))
            ->assertOk()
            ->assertSee('Recent Event')
            ->assertDontSee('Last Event');
    }

    public function test_guests_can_open_the_standalone_public_leaderboard(): void
    {
        $event = Event::factory()->create(['name' => 'University Games']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $category->competitions()->create(['name' => 'Basketball', 'scores_finalized_at' => now()]);

        $this->get(route('leaderboards.show', $event))
            ->assertOk()
            ->assertSee('University Games')
            ->assertSee('By category')
            ->assertSee('By game')
            ->assertSee('Basketball')
            ->assertSee('const dataUrl =', false);

        $this->get(route('events.leaderboard', $event))->assertRedirect(route('login'));
    }

    public function test_public_leaderboard_shows_participant_photos_and_placeholder(): void
    {
        Storage::fake('public');

        $event = Event::factory()->create();
        $picturePath = "participants/{$event->id}/alex.jpg";
        Storage::disk('public')->put($picturePath, 'profile-picture');

        $alex = $event->participants()->create([
            'name' => 'Alex Santos',
            'profile_picture_path' => $picturePath,
        ]);
        $bea = $event->participants()->create(['name' => 'Bea Cruz']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $competition->results()->create(['participant_id' => $alex->id, 'wins' => 3]);
        $competition->results()->create(['participant_id' => $bea->id, 'wins' => 2]);

        $pictureUrl = Storage::disk('public')->url($picturePath);
        $placeholderUrl = asset('images/participant-placeholder.svg');

        $this->get(route('leaderboards.show', $event))
            ->assertOk()
            ->assertSee('src="'.$pictureUrl.'"', false)
            ->assertSee('src="'.$placeholderUrl.'"', false)
            ->assertSee('Alex Santos profile picture')
            ->assertSee('Participant photo placeholder')
            ->assertDontSee('Department');

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonPath('rows.0.profile_image_url', $pictureUrl)
            ->assertJsonPath('rows.1.profile_image_url', null)
            ->assertJsonMissingPath('rows.0.department');

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'game', 'competition_id' => $competition->id]))
            ->assertOk()
            ->assertJsonPath('rows.0.profile_image_url', $pictureUrl);
    }

    public function test_public_data_includes_only_finalized_contests_and_excludes_non_entries(): void
    {
        $event = Event::factory()->create();
        $winner = $event->participants()->create(['name' => 'Alex Santos']);
        $unfinalized = $event->participants()->create(['name' => 'Bea Cruz']);
        $withoutEntry = $event->participants()->create(['name' => 'Carlo Reyes']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $finalized = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $pending = $category->competitions()->create([
            'name' => 'Volleyball',
            'scoring_method' => CompetitionScoringMethod::Wins,
        ]);
        $finalized->rankScores()->create(['rank' => 1, 'points' => 100]);
        $finalized->results()->create(['participant_id' => $winner->id, 'wins' => 3]);
        $finalized->results()->create([
            'participant_id' => $withoutEntry->id,
            'has_entry' => false,
            'wins' => null,
        ]);
        $pending->results()->create(['participant_id' => $unfinalized->id, 'wins' => 5]);

        $this->get(route('leaderboards.show', $event))
            ->assertOk()
            ->assertSee('Alex Santos')
            ->assertDontSee('Bea Cruz')
            ->assertDontSee('Carlo Reyes')
            ->assertSee('Live finalized results');

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('frozen', false)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.name', 'Alex Santos')
            ->assertJsonPath('rows.0.contest_wins', 1)
            ->assertJsonPath('rows.0.points', 100);

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'game', 'competition_id' => $finalized->id]))
            ->assertOk()
            ->assertJsonPath('rows.0.result', '3 wins')
            ->assertJsonCount(1, 'rows');

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'game', 'competition_id' => $pending->id]))
            ->assertOk()
            ->assertJsonCount(0, 'rows');
    }

    public function test_public_page_places_overall_table_before_category_grouped_contest_cards(): void
    {
        $event = Event::factory()->create();
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $sports = $event->categories()->create(['name' => 'Sports']);
        $finalized = $sports->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $sports->competitions()->create(['name' => 'Volleyball', 'scoring_method' => CompetitionScoringMethod::Wins]);
        $finalized->results()->create(['participant_id' => $participant->id, 'wins' => 4]);

        $response = $this->get(route('leaderboards.show', $event))->assertOk();
        $content = $response->getContent();
        $this->assertLessThan(strpos($content, 'Contests by category'), strpos($content, 'Overall score'));
        $response->assertSee('Basketball')->assertDontSee('Volleyball')->assertSee('Category filter');
        $response->assertSee('id="apply-contest-filter"', false)->assertSee('Apply filter');

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonPath('rows.0.name', 'Alex Santos')
            ->assertJsonPath('categories.0.name', 'Sports')
            ->assertJsonPath('categories.0.contests.0.name', 'Basketball')
            ->assertJsonCount(1, 'categories.0.contests');
    }

    public function test_apply_filter_data_loads_selected_contest_cards_but_keeps_overall_scores(): void
    {
        $event = Event::factory()->create();
        $alex = $event->participants()->create(['name' => 'Alex Santos']);
        $bea = $event->participants()->create(['name' => 'Bea Cruz']);
        $sports = $event->categories()->create(['name' => 'Sports']);
        $arts = $event->categories()->create(['name' => 'Arts']);
        $basketball = $sports->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $painting = $arts->competitions()->create([
            'name' => 'Painting',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $basketball->results()->create(['participant_id' => $alex->id, 'wins' => 4]);
        $painting->results()->create(['participant_id' => $bea->id, 'wins' => 3]);

        $this->getJson(route('leaderboards.data', [$event, 'category_id' => $sports->id]))
            ->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonCount(1, 'categories')
            ->assertJsonPath('categories.0.name', 'Sports')
            ->assertJsonPath('categories.0.contests.0.name', 'Basketball')
            ->assertJsonMissing(['name' => 'Painting'], false);

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonCount(2, 'categories');

        $otherEvent = Event::factory()->create();
        $otherCategory = $otherEvent->categories()->create(['name' => 'Other']);
        $this->getJson(route('leaderboards.data', [$event, 'category_id' => $otherCategory->id]))
            ->assertNotFound();
    }

    public function test_category_filter_is_scoped_to_its_finalized_contests(): void
    {
        $event = Event::factory()->create();
        $alex = $event->participants()->create(['name' => 'Alex Santos']);
        $bea = $event->participants()->create(['name' => 'Bea Cruz']);
        $sports = $event->categories()->create(['name' => 'Sports']);
        $arts = $event->categories()->create(['name' => 'Arts']);
        $basketball = $sports->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $painting = $arts->competitions()->create([
            'name' => 'Painting',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $basketball->results()->create(['participant_id' => $alex->id, 'wins' => 2]);
        $painting->results()->create(['participant_id' => $bea->id, 'wins' => 3]);

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'category', 'category_id' => $sports->id]))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.name', 'Alex Santos');

        $otherEvent = Event::factory()->create();
        $otherCategory = $otherEvent->categories()->create(['name' => 'Other']);
        $otherCompetition = $otherCategory->competitions()->create(['name' => 'Other game']);

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'category', 'category_id' => $otherCategory->id]))
            ->assertNotFound();
        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'game', 'competition_id' => $otherCompetition->id]))
            ->assertNotFound();
    }

    public function test_public_leaderboard_requires_an_id_for_filtered_views(): void
    {
        $event = Event::factory()->create();

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'category']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'game']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('competition_id');

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'invalid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');
    }

    public function test_criteria_based_game_displays_the_adjusted_score(): void
    {
        $event = Event::factory()->create();
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create([
            'name' => 'Painting',
            'scores_finalized_at' => now(),
        ]);
        $criterion = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $result = $competition->results()->create([
            'participant_id' => $participant->id,
            'deduction' => 2.5,
        ]);
        $result->criterionScores()->create(['criterion_id' => $criterion->id, 'score' => 87]);

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'game', 'competition_id' => $competition->id]))
            ->assertOk()
            ->assertJsonPath('rows.0.name', 'Alex Santos')
            ->assertJsonPath('rows.0.result', '84.50');
    }

    public function test_freezing_hides_all_public_scores_until_unfrozen(): void
    {
        $event = Event::factory()->create(['leaderboard_frozen' => true]);
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $competition->results()->create(['participant_id' => $participant->id, 'wins' => 4]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonPath('frozen', true)
            ->assertJsonCount(0, 'rows')
            ->assertDontSee('Alex Santos');

        $this->get(route('leaderboards.show', $event))
            ->assertOk()
            ->assertSee('Public results frozen')
            ->assertDontSee('Alex Santos');

        $event->update(['leaderboard_frozen' => false]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonPath('frozen', false)
            ->assertJsonPath('rows.0.name', 'Alex Santos');
    }
}
