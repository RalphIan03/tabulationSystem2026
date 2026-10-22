<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\Event;
use App\Models\User;
use App\Services\PublicLeaderboardData;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PublicLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('publicBracketContests')]
    public function test_public_contest_cards_include_saved_bracket_links_before_and_after_finalization(CompetitionScoringMethod $method, bool $finalized): void
    {
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $url = 'https://example.com/bracket?round=2&view=full';
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => $method,
            'scores_finalized_at' => $finalized ? now() : null,
            'bracket_url' => $url,
        ]);
        Cache::put("leaderboard:event:{$event->id}:snapshot:v5", ['rows' => [], 'categories' => [], 'win_categories' => []], 120);

        $response = $this->get(route('leaderboards.show', $event));

        $links = $this->xpath($response->getContent())->query('//article[@data-contest-card="'.$competition->id.'"]//a[@data-bracket-link]');
        $this->assertSame(1, $links->length);
        $link = $links->item(0);
        $this->assertSame($url, $link->getAttribute('href'));
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertSame('noopener noreferrer', $link->getAttribute('rel'));
        $this->assertSame('Open bracket', trim($link->textContent));
        $response->assertSee('href="https://example.com/bracket?round=2&amp;view=full"', false);
        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('categories.0.contests.0.bracket_url', $url)
            ->assertJsonPath('categories.0.contests.0.finalized', $finalized);
    }

    #[DataProvider('hiddenBracketLinks')]
    public function test_public_cards_hide_missing_unsupported_or_unsafe_bracket_links(CompetitionScoringMethod $method, ?string $url): void
    {
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $category->competitions()->create(['name' => 'Contest', 'scoring_method' => $method, 'bracket_url' => $url]);

        $response = $this->get(route('leaderboards.show', $event));

        $this->assertSame(0, $this->xpath($response->getContent())->query('//article//a[@data-bracket-link]')->length);
        $this->getJson(route('leaderboards.data', $event))->assertJsonPath('categories.0.contests.0.bracket_url', null);
    }

    public function test_bracket_changes_refresh_public_filter_data_and_clearing_removes_the_button(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $sports = $event->categories()->create(['name' => 'Sports']);
        $arts = $event->categories()->create(['name' => 'Arts']);
        $competition = $sports->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Ranking,
            'bracket_url' => 'https://example.com/original',
        ]);
        $arts->competitions()->create(['name' => 'Other contest', 'scoring_method' => CompetitionScoringMethod::Wins, 'bracket_url' => 'https://example.com/other']);
        $dataRoute = route('leaderboards.data', [$event, 'category_id' => $sports->id]);
        $updateRoute = route('events.categories.competitions.bracket.update', [$event, $sports, $competition]);

        $this->getJson($dataRoute)->assertJsonCount(1, 'categories')->assertJsonPath('categories.0.contests.0.bracket_url', 'https://example.com/original');
        $this->actingAs($admin)->patch($updateRoute, ['bracket_url' => 'https://example.com/updated'])->assertRedirect()->assertSessionHasNoErrors();
        $this->getJson($dataRoute)->assertJsonCount(1, 'categories')->assertJsonPath('categories.0.contests.0.bracket_url', 'https://example.com/updated');
        $this->patch($updateRoute, ['bracket_url' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $this->getJson($dataRoute)->assertJsonPath('categories.0.contests.0.bracket_url', null);

        $response = $this->get(route('leaderboards.show', $event));
        $this->assertSame(0, $this->xpath($response->getContent())->query('//article[@data-contest-card="'.$competition->id.'"]//a[@data-bracket-link]')->length);
    }

    public function test_frozen_leaderboards_do_not_expose_external_bracket_links(): void
    {
        $event = Event::factory()->create(['leaderboard_frozen' => true]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'bracket_url' => 'https://example.com/hidden-bracket',
            'scores_finalized_at' => now(),
        ]);

        $response = $this->get(route('leaderboards.show', $event));

        $this->assertSame(0, $this->xpath($response->getContent())->query('//a[@data-bracket-link]')->length);
        $response->assertDontSee('hidden-bracket');
        $this->getJson(route('leaderboards.data', $event))->assertJsonCount(0, 'categories')->assertDontSee('hidden-bracket');
    }

    public static function publicBracketContests(): array
    {
        return [
            'pending wins' => [CompetitionScoringMethod::Wins, false],
            'finalized wins' => [CompetitionScoringMethod::Wins, true],
            'pending ranking' => [CompetitionScoringMethod::Ranking, false],
            'finalized ranking' => [CompetitionScoringMethod::Ranking, true],
        ];
    }

    public static function hiddenBracketLinks(): array
    {
        return [
            'no link' => [CompetitionScoringMethod::Wins, null],
            'empty link' => [CompetitionScoringMethod::Ranking, ''],
            'criteria contest' => [CompetitionScoringMethod::Criteria, 'https://example.com/bracket'],
            'javascript link' => [CompetitionScoringMethod::Wins, 'javascript:alert(1)'],
            'data link' => [CompetitionScoringMethod::Ranking, 'data:text/html,<script>alert(1)</script>'],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    public function test_repeated_public_snapshot_avoids_reloading_event_scoring_data(): void
    {
        $event = Event::factory()->create();
        $event->categories()->create(['name' => 'Sports']);
        $leaderboard = app(PublicLeaderboardData::class);

        $leaderboard->snapshot($event);
        $anotherEventInstance = $event->fresh();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $snapshot = $leaderboard->snapshot($anotherEventInstance);

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame('Sports', $snapshot['categories'][0]['name']);
        DB::disableQueryLog();
    }

    public function test_saved_scores_refresh_cached_public_snapshot(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->for($admin, 'creator')->create();
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $otherParticipant = $event->participants()->create(['name' => 'Bea Cruz']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $competition->results()->create(['participant_id' => $participant->id, 'wins' => 2]);
        $competition->results()->create(['participant_id' => $otherParticipant->id, 'wins' => 4]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('categories.0.contests.0.rows.0.name', 'Bea Cruz')
            ->assertJsonPath('categories.0.contests.0.rows.1.name', 'Alex Santos');

        $this->actingAs($admin)
            ->patch(route('events.categories.competitions.scores.update', [$event, $category, $competition]), [
                'wins' => [$participant->id => 5, $otherParticipant->id => 4],
            ])
            ->assertRedirect();

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('categories.0.contests.0.rows.0.name', 'Alex Santos')
            ->assertJsonPath('categories.0.contests.0.rows.1.name', 'Bea Cruz');
    }

    public function test_public_overall_combines_subteam_rank_points_under_parent_team(): void
    {
        $event = Event::factory()->create();
        $teamA = $event->participants()->create(['name' => 'TEAM A']);
        $teamA2 = $event->participants()->create(['name' => 'TEAM A2', 'parent_participant_id' => $teamA->id]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Relay',
            'scoring_method' => CompetitionScoringMethod::Ranking,
            'scores_finalized_at' => now(),
        ]);
        $competition->rankScores()->createMany([
            ['rank' => 1, 'points' => 100],
            ['rank' => 2, 'points' => 50],
        ]);
        $competition->results()->createMany([
            ['participant_id' => $teamA2->id, 'placement' => 1],
            ['participant_id' => $teamA->id, 'placement' => 2],
        ]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.name', 'TEAM A')
            ->assertJsonMissingPath('rows.0.points');
        $this->assertSame(150.0, app(PublicLeaderboardData::class)->snapshot($event)['rows'][0]['points']);
    }

    public function test_participant_and_rank_configuration_changes_refresh_cached_public_snapshot(): void
    {
        $event = Event::factory()->create();
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $competition->results()->create(['participant_id' => $participant->id, 'wins' => 2]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('rows.0.name', 'Alex Santos')
            ->assertJsonMissingPath('rows.0.points');
        $this->assertSame(0.0, app(PublicLeaderboardData::class)->snapshot($event)['rows'][0]['points']);

        $participant->update(['name' => 'Alex Reyes']);
        $competition->rankScores()->create(['rank' => 1, 'points' => 100]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('rows.0.name', 'Alex Reyes')
            ->assertJsonMissingPath('rows.0.points');
        $this->assertSame(100.0, app(PublicLeaderboardData::class)->snapshot($event)['rows'][0]['points']);
    }

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
            ->assertSee('Contests by category')
            ->assertSee('Rankings appear after scores are finalized')
            ->assertSee('Basketball')
            ->assertSee('const dataUrl =', false)
            ->assertSee('new URL(dataUrl, window.location.href)', false)
            ->assertViewHas('dataUrl', route('leaderboards.data', $event, false));

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

    public function test_public_data_shows_finalized_rankings_and_pending_cards_without_non_entries(): void
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
            ->assertJsonMissingPath('rows.0.points');

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('categories.0.contests.0.finalized', true)
            ->assertJsonPath('categories.0.contests.0.rows.0.name', 'Alex Santos')
            ->assertJsonMissingPath('categories.0.contests.0.rows.0.points')
            ->assertJsonMissingPath('categories.0.contests.0.rows.0.result')
            ->assertJsonPath('categories.0.contests.1.name', 'Volleyball')
            ->assertJsonPath('categories.0.contests.1.finalized', false)
            ->assertJsonCount(0, 'categories.0.contests.1.rows');

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
        $response->assertSee('Basketball')->assertSee('Volleyball')->assertSee('No finalized score')->assertSee('Category filter');
        $response->assertSee('id="apply-contest-filter"', false)->assertSee('Apply filter');
        $cardsStart = strpos($content, '<div id="contest-cards"');
        $this->assertNotFalse($cardsStart);
        $cardsEnd = strpos($content, '</section>', $cardsStart);
        $this->assertNotFalse($cardsEnd);
        $cards = substr($content, $cardsStart, $cardsEnd - $cardsStart);
        $this->assertStringNotContainsString('Points</th>', $cards);
        $this->assertStringNotContainsString('4 wins', $cards);

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonPath('rows.0.name', 'Alex Santos')
            ->assertJsonPath('categories.0.name', 'Sports')
            ->assertJsonPath('categories.0.contests.0.name', 'Basketball')
            ->assertJsonPath('categories.0.contests.1.name', 'Volleyball')
            ->assertJsonCount(2, 'categories.0.contests');
    }

    public function test_pending_contest_card_changes_to_rankings_only_after_finalization(): void
    {
        $event = Event::factory()->create();
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
        ]);
        $competition->results()->create(['participant_id' => $participant->id, 'wins' => 9]);

        $this->get(route('leaderboards.show', $event))
            ->assertSee('Basketball')
            ->assertSee('No finalized score')
            ->assertDontSee('Alex Santos');
        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonCount(0, 'rows')
            ->assertJsonPath('categories.0.contests.0.finalized', false)
            ->assertJsonCount(0, 'categories.0.contests.0.rows');

        $competition->update(['scores_finalized_at' => now()]);

        $finalizedPage = $this->get(route('leaderboards.show', $event))
            ->assertSee('Basketball')
            ->assertSee('Alex Santos');
        $cardStart = strpos($finalizedPage->getContent(), '<article data-contest-card="'.$competition->id.'"');
        $this->assertNotFalse($cardStart);
        $cardEnd = strpos($finalizedPage->getContent(), '</article>', $cardStart);
        $this->assertNotFalse($cardEnd);
        $this->assertStringNotContainsString(
            'No finalized score',
            substr($finalizedPage->getContent(), $cardStart, $cardEnd - $cardStart),
        );
        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('categories.0.contests.0.finalized', true)
            ->assertJsonPath('categories.0.contests.0.rows.0.rank', 1)
            ->assertJsonPath('categories.0.contests.0.rows.0.name', 'Alex Santos')
            ->assertJsonMissingPath('categories.0.contests.0.rows.0.points')
            ->assertJsonMissingPath('categories.0.contests.0.rows.0.result');
    }

    public function test_finalized_card_shows_configured_winning_ranks_but_not_non_podium_entrants(): void
    {
        $event = Event::factory()->create();
        $winner = $event->participants()->create(['name' => 'Alex Santos']);
        $nonPodium = $event->participants()->create(['name' => 'Bea Cruz']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $competition->rankScores()->create(['rank' => 1, 'points' => 100]);
        $competition->results()->create(['participant_id' => $winner->id, 'wins' => 5]);
        $competition->results()->create(['participant_id' => $nonPodium->id, 'wins' => 2]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonCount(2, 'rows')
            ->assertJsonCount(1, 'categories.0.contests.0.rows')
            ->assertJsonPath('categories.0.contests.0.rows.0.rank', 1)
            ->assertJsonPath('categories.0.contests.0.rows.0.name', 'Alex Santos');
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

    public function test_overall_leaderboard_counts_first_place_finishes_for_each_category(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $teamA = $event->participants()->create(['name' => 'TEAM A']);
        $teamA2 = $event->participants()->create(['name' => 'TEAM A2', 'parent_participant_id' => $teamA->id]);
        $teamB = $event->participants()->create(['name' => 'TEAM B']);
        $sports = $event->categories()->create(['name' => 'Sports']);
        $arts = $event->categories()->create(['name' => 'Arts']);
        $basketball = $sports->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $relay = $sports->competitions()->create([
            'name' => 'Relay',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $painting = $arts->competitions()->create([
            'name' => 'Painting',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $basketball->results()->createMany([
            ['participant_id' => $teamA->id, 'wins' => 5],
            ['participant_id' => $teamB->id, 'wins' => 2],
        ]);
        $relay->results()->createMany([
            ['participant_id' => $teamA2->id, 'wins' => 4],
            ['participant_id' => $teamB->id, 'wins' => 1],
        ]);
        $painting->results()->createMany([
            ['participant_id' => $teamA->id, 'wins' => 1],
            ['participant_id' => $teamB->id, 'wins' => 3],
        ]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonPath('rows.0.name', 'TEAM A')
            ->assertJsonPath('rows.0.contest_wins', 2)
            ->assertJsonPath("rows.0.category_wins.{$sports->id}", 2)
            ->assertJsonPath("rows.0.category_wins.{$arts->id}", 0)
            ->assertJsonPath('rows.1.name', 'TEAM B')
            ->assertJsonPath("rows.1.category_wins.{$sports->id}", 0)
            ->assertJsonPath("rows.1.category_wins.{$arts->id}", 1)
            ->assertJsonPath('win_categories.0.name', 'Arts')
            ->assertJsonPath('win_categories.1.name', 'Sports')
            ->assertJsonMissingPath('rows.0.points');

        $this->get(route('leaderboards.show', $event))
            ->assertSee('Arts wins')
            ->assertSee('Sports wins')
            ->assertDontSee('>Total points</th>', false);
        $this->actingAs($admin)
            ->get(route('events.leaderboard', $event))
            ->assertSee('Arts wins')
            ->assertSee('Sports wins');
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

    public function test_freezing_shows_only_total_points_and_unfreezing_shows_teams_and_wins(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $competition->rankScores()->create(['rank' => 1, 'points' => 100]);
        $competition->results()->create(['participant_id' => $participant->id, 'wins' => 4]);

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('rows.0.name', 'Alex Santos')
            ->assertJsonPath('rows.0.contest_wins', 1)
            ->assertJsonMissingPath('rows.0.points');
        $this->get(route('leaderboards.show', $event))
            ->assertSee('Alex Santos')
            ->assertSee('Sports wins')
            ->assertDontSee('>Total points</th>', false);

        $this->actingAs($admin)
            ->patch(route('events.leaderboard-freeze', $event))
            ->assertRedirect();
        $this->assertTrue($event->refresh()->leaderboard_frozen);

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonPath('frozen', true)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.rank', 1)
            ->assertJsonPath('rows.0.points', 100)
            ->assertJsonMissingPath('rows.0.name')
            ->assertJsonMissingPath('rows.0.contest_wins')
            ->assertJsonCount(0, 'categories')
            ->assertJsonCount(0, 'win_categories')
            ->assertDontSee('Alex Santos');

        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'game', 'competition_id' => $competition->id]))
            ->assertJsonCount(0, 'rows');

        $otherEvent = Event::factory()->create();
        $otherCategory = $otherEvent->categories()->create(['name' => 'Other']);
        $this->getJson(route('leaderboards.data', [$event, 'scope' => 'category', 'category_id' => $otherCategory->id]))
            ->assertNotFound();

        $this->get(route('leaderboards.show', $event))
            ->assertOk()
            ->assertSee('Points-only preview')
            ->assertSee('>Total points</th>', false)
            ->assertDontSee('Alex Santos')
            ->assertDontSee('Sports wins')
            ->assertDontSee('Basketball');

        $this->actingAs($admin)
            ->patch(route('events.leaderboard-freeze', $event))
            ->assertRedirect();
        $this->assertFalse($event->refresh()->leaderboard_frozen);

        $this->getJson(route('leaderboards.data', $event))
            ->assertOk()
            ->assertJsonPath('frozen', false)
            ->assertJsonPath('rows.0.name', 'Alex Santos')
            ->assertJsonMissingPath('rows.0.points');
    }
}
