<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\ActivityLog;
use App\Models\Competition;
use App\Models\Event;
use App\Models\Participant;
use App\Models\User;
use App\Services\LeaderboardCalculator;
use App\Services\PublicLeaderboardData;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EventDeductionsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_admins_and_auditors_can_deduct_overall_points_once_per_main_team(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event, $competition, $teamA, $teamB, $linked] = $this->eventWithPoints();

        $this->actingAs($user)->patch(route('events.overall-deductions.update', $event), [
            'deductions' => [$teamA->id => 20.25, $teamB->id => 5],
            'name' => 'Unexpected event rename',
            'leaderboard_frozen' => true,
        ])->assertRedirect(route('events.leaderboard', $event))
            ->assertSessionHas('status', 'Overall event deductions saved successfully.');

        $this->assertSame('20.25', $teamA->refresh()->overall_deduction);
        $this->assertSame('5.00', $teamB->refresh()->overall_deduction);
        $this->assertSame('0.00', $linked->refresh()->overall_deduction);
        $this->assertSame('Event Games', $event->refresh()->name);
        $this->assertFalse($event->leaderboard_frozen);
        $standings = app(LeaderboardCalculator::class)->overallStandings($event->fresh());
        $this->assertSame([160.0, 40.0], $standings->pluck('gross_points')->all());
        $this->assertSame([139.75, 35.0], $standings->pluck('leaderboard_points')->all());
        $this->assertSame([1, 0], $standings->pluck('contest_wins')->all());
        $this->assertSame([100.0, 60.0, 40.0], app(LeaderboardCalculator::class)->competitionStandings($competition->fresh())->pluck('leaderboard_points')->all());
        $this->assertTrue($competition->refresh()->scoresAreFinalized());

        $page = $this->get(route('events.leaderboard', $event))->assertSee('Save overall deductions')
            ->assertSee('action="'.route('events.overall-deductions.update', $event, false).'"', false);
        $document = new DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame('160.00', trim($xpath->query('//tr[@data-overall-deduction-row="'.$teamA->id.'"]/td[2]')->item(0)->textContent));
        $this->assertSame('139.75', trim($xpath->query('//tr[@data-overall-deduction-row="'.$teamA->id.'"]/td[4]')->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//input[@name="deductions['.$linked->id.']"]')->length);
        $log = ActivityLog::query()->where('route_name', 'events.overall-deductions.update')->latest('id')->firstOrFail();
        $this->assertSame('Updated overall event point deductions', $log->description);
        $this->assertSame([(string) $teamA->id, (string) $teamB->id], array_column($log->context['records'], 'id'));
    }

    public function test_event_deductions_refresh_public_net_points_without_leaking_hidden_values(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event, $competition, $teamA] = $this->eventWithPoints();
        $event->update(['leaderboard_frozen' => true]);
        $this->getJson(route('leaderboards.data', $event))->assertJsonPath('rows.0.points', 160);

        $this->actingAs($auditor)->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => 20]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('rows.0.points', 140)
            ->assertJsonMissingPath('rows.0.name')
            ->assertJsonMissingPath('rows.0.gross_points')
            ->assertJsonMissingPath('rows.0.overall_deduction');
        $event->update(['leaderboard_frozen' => false]);
        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('rows.0.name', 'TEAM A')
            ->assertJsonMissingPath('rows.0.points')
            ->assertJsonMissingPath('rows.0.gross_points')
            ->assertJsonMissingPath('rows.0.overall_deduction');
        $data = app(PublicLeaderboardData::class);
        $categoryRows = $data->rows($event->fresh(), 'category', (int) $competition->category_id, null);
        $contestRows = $data->rows($event->fresh(), 'game', null, (int) $competition->id);
        $this->assertSame(160.0, $categoryRows[0]['points']);
        $this->assertSame(100.0, $contestRows[0]['points']);
    }

    public function test_overall_deductions_are_included_in_the_event_results_download(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $competition, $teamA] = $this->eventWithPoints();
        $teamA->update(['overall_deduction' => 20.25]);

        $response = $this->actingAs($admin)->get(route('events.results.download', $event))->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('"Leaderboard points","Contests entered","Earned points","Overall deduction"', $content);
        $this->assertStringContainsString('1,"TEAM A",1,0,139.75,1,160,20.25', $content);
        $this->assertSame(100.0, app(LeaderboardCalculator::class)->competitionStandings($competition->fresh())->first()['leaderboard_points']);
    }

    public function test_net_points_are_clamped_to_zero_without_changing_win_first_ordering(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event, $competition, $teamA] = $this->eventWithPoints();

        $this->actingAs($auditor)->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => 200]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $standings = app(LeaderboardCalculator::class)->overallStandings($event->fresh());
        $this->assertSame(['TEAM A', 'TEAM B'], $standings->pluck('participant.name')->all());
        $this->assertSame([0.0, 40.0], $standings->pluck('leaderboard_points')->all());
        $this->assertSame([1, 0], $standings->pluck('contest_wins')->all());
    }

    public function test_net_overall_points_break_ties_after_event_deductions(): void
    {
        [$event, $competition, $teamA, $teamB] = $this->eventWithPoints();
        $other = $competition->category->competitions()->create(['name' => 'Second contest', 'scoring_method' => CompetitionScoringMethod::Ranking]);
        $other->rankScores()->create(['rank' => 1, 'points' => 100]);
        $other->results()->create(['participant_id' => $teamB->id, 'placement' => 1]);
        $teamA->update(['overall_deduction' => 30]);

        $standings = app(LeaderboardCalculator::class)->overallStandings($event->fresh());

        $this->assertSame(['TEAM B', 'TEAM A'], $standings->pluck('participant.name')->all());
        $this->assertSame([1, 1], $standings->pluck('contest_wins')->all());
        $this->assertSame([140.0, 130.0], $standings->pluck('leaderboard_points')->all());
    }

    #[DataProvider('clearedDeductions')]
    public function test_zero_or_blank_clears_deductions_and_omitted_teams_are_preserved(mixed $value): void
    {
        $user = User::factory()->create();
        [$event, $competition, $teamA, $teamB] = $this->eventWithPoints();
        $teamA->update(['overall_deduction' => 10]);
        $teamB->update(['overall_deduction' => 7]);

        $this->actingAs($user)->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => $value]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('0.00', $teamA->refresh()->overall_deduction);
        $this->assertSame('7.00', $teamB->refresh()->overall_deduction);
    }

    #[DataProvider('invalidDeductions')]
    public function test_invalid_deductions_are_rejected_without_updating_any_team(mixed $value, string $message): void
    {
        $user = User::factory()->create();
        [$event, $competition, $teamA, $teamB] = $this->eventWithPoints();

        $this->actingAs($user)->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => $value, $teamB->id => 5]])
            ->assertSessionHasErrors(['deductions.'.$teamA->id => str_replace(':index', (string) $teamA->id, $message)]);

        $this->assertSame('0.00', $teamA->refresh()->overall_deduction);
        $this->assertSame('0.00', $teamB->refresh()->overall_deduction);
    }

    public function test_linked_and_foreign_participants_cannot_receive_event_deductions(): void
    {
        $user = User::factory()->create();
        [$event, $competition, $teamA, $teamB, $linked] = $this->eventWithPoints();
        $otherEvent = Event::factory()->create();
        $outside = $otherEvent->participants()->create(['name' => 'Outside team']);

        $this->actingAs($user)->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => 10, $linked->id => 5]])->assertSessionHasErrors('deductions');
        $this->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => 10, $outside->id => 5]])->assertSessionHasErrors('deductions');

        $this->assertSame('0.00', $teamA->refresh()->overall_deduction);
        $this->assertSame('0.00', $linked->refresh()->overall_deduction);
        $this->assertSame('0.00', $outside->refresh()->overall_deduction);
    }

    public function test_events_without_main_teams_reject_deduction_submissions(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->actingAs($user)->patch(route('events.overall-deductions.update', $event), ['deductions' => [999 => 10]])
            ->assertSessionHasErrors(['deductions' => 'Add a main team or participant before entering overall deductions.']);

        $this->assertDatabaseCount('participants', 0);
        $this->get(route('events.leaderboard', $event))->assertDontSee('Save overall deductions');
    }

    public function test_guests_cannot_save_event_deductions(): void
    {
        [$event, $competition, $teamA] = $this->eventWithPoints();

        $this->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => 10]])->assertRedirect(route('login'));

        $this->assertSame('0.00', $teamA->refresh()->overall_deduction);
    }

    #[DataProvider('roles')]
    public function test_archived_events_cannot_have_their_deductions_changed(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event, $competition, $teamA] = $this->eventWithPoints();
        $event->update(['archived_at' => now()]);

        $response = $this->actingAs($user)->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => 10]]);

        $response->assertStatus($role === AccountRole::Admin ? 403 : 404);
        $this->assertSame('0.00', $teamA->refresh()->overall_deduction);
    }

    public function test_inactive_events_are_hidden_from_auditors_for_deduction_updates(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event, $competition, $teamA] = $this->eventWithPoints();
        $event->update(['is_active' => false]);

        $this->actingAs($auditor)->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamA->id => 10]])->assertNotFound();

        $this->assertSame('0.00', $teamA->refresh()->overall_deduction);
    }

    #[DataProvider('invalidPayloads')]
    public function test_missing_or_malformed_deduction_payload_is_rejected(array $payload): void
    {
        $user = User::factory()->create();
        [$event, $competition, $teamA] = $this->eventWithPoints();

        $this->actingAs($user)->patch(route('events.overall-deductions.update', $event), $payload)->assertSessionHasErrors('deductions');

        $this->assertSame('0.00', $teamA->refresh()->overall_deduction);
    }

    public static function roles(): array
    {
        return ['admin' => [AccountRole::Admin], 'auditor' => [AccountRole::Auditor]];
    }

    public static function invalidPayloads(): array
    {
        return ['missing' => [[]], 'empty' => [['deductions' => []]], 'not an array' => [['deductions' => 'invalid']]];
    }

    public static function clearedDeductions(): array
    {
        return ['zero' => [0], 'blank' => [''], 'null' => [null]];
    }

    public static function invalidDeductions(): array
    {
        return [
            'negative' => [-0.01, 'The deductions.:index field must be at least 0.'],
            'too precise' => [0.001, 'The deductions.:index field must have 0-2 decimal places.'],
            'not numeric' => ['invalid', 'The deductions.:index field must be a number.'],
            'nested array' => [['amount' => 5], 'The deductions.:index field must be a number.'],
            'too large' => [10000000000, 'The deductions.:index field must not be greater than 9999999999.99.'],
        ];
    }

    /** @return array{Event, Competition, Participant, Participant, Participant} */
    private function eventWithPoints(): array
    {
        $event = Event::factory()->create(['name' => 'Event Games']);
        $teamA = $event->participants()->create(['name' => 'TEAM A']);
        $teamB = $event->participants()->create(['name' => 'TEAM B']);
        $linked = $event->participants()->create(['name' => 'TEAM A2', 'parent_participant_id' => $teamA->id]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Race', 'scoring_method' => CompetitionScoringMethod::Ranking, 'scores_finalized_at' => now()]);
        $competition->rankScores()->createMany([
            ['rank' => 1, 'points' => 100], ['rank' => 2, 'points' => 60], ['rank' => 3, 'points' => 40],
        ]);
        $competition->results()->createMany([
            ['participant_id' => $linked->id, 'placement' => 1], ['participant_id' => $teamA->id, 'placement' => 2], ['participant_id' => $teamB->id, 'placement' => 3],
        ]);

        return [$event, $competition, $teamA, $teamB, $linked];
    }
}
