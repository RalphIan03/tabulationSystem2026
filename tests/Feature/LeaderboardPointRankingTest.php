<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Enums\TieRankingMethod;
use App\Models\Competition;
use App\Models\Event;
use App\Models\Participant;
use App\Models\User;
use App\Services\LeaderboardCalculator;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

final class LeaderboardPointRankingTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('staffLeaderboardStates')]
    public function test_admins_and_auditors_see_each_teams_net_points_in_overall_standings(AccountRole $role, bool $frozen): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event] = $this->overallEvent();
        $event->update(['leaderboard_frozen' => $frozen]);

        $response = $this->actingAs($user)->get(route('events.leaderboard', $event));

        $xpath = $this->xpath($response->getContent());
        $headings = $xpath->query('//table[@id="overall-standings-table"]/thead/tr/th');
        $this->assertSame('Net points', trim($headings->item($headings->length - 1)->textContent));
        $rows = $xpath->query('//table[@id="overall-standings-table"]/tbody/tr');
        $this->assertSame(2, $rows->length);
        $names = [];
        $points = [];

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            $names[] = trim($cells->item(1)->textContent);
            $points[] = trim($cells->item($cells->length - 1)->textContent);
        }

        $this->assertSame($frozen ? ['TEAM B', 'TEAM A'] : ['TEAM A', 'TEAM B'], $names);
        $this->assertSame($frozen ? ['90.00', '40.00'] : ['40.00', '90.00'], $points);
    }

    public function test_frozen_public_leaderboards_rank_by_net_points_instead_of_contest_wins_and_refresh_after_deductions(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $teamA, $teamB] = $this->overallEvent();
        $dataRoute = route('leaderboards.data', $event);

        $this->getJson($dataRoute)->assertJsonPath('rows.0.name', 'TEAM A')->assertJsonMissingPath('rows.0.points');
        $this->actingAs($admin)->patch(route('events.leaderboard-freeze', $event))->assertRedirect();

        $this->getJson($dataRoute)
            ->assertJsonPath('frozen', true)
            ->assertJsonPath('rows.0.rank', 1)->assertJsonPath('rows.0.points', 90)
            ->assertJsonPath('rows.1.rank', 2)->assertJsonPath('rows.1.points', 40)
            ->assertJsonMissingPath('rows.0.name')->assertJsonMissingPath('rows.0.contest_wins')
            ->assertJsonCount(0, 'categories');
        $page = $this->get(route('leaderboards.show', $event));
        $this->assertSame('90.00', trim($this->xpath($page->getContent())->query('//tbody[@id="leaderboard-rows"]/tr[1]/td[2]')->item(0)->textContent));

        $this->patch(route('events.overall-deductions.update', $event), ['deductions' => [$teamB->id => 80]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->getJson($dataRoute)
            ->assertJsonPath('rows.0.rank', 1)->assertJsonPath('rows.0.points', 40)
            ->assertJsonPath('rows.1.rank', 2)->assertJsonPath('rows.1.points', 20);

        $this->patch(route('events.leaderboard-freeze', $event))->assertRedirect();
        $this->getJson($dataRoute)->assertJsonPath('frozen', false)->assertJsonPath('rows.0.name', 'TEAM A')->assertJsonMissingPath('rows.0.points');
    }

    #[DataProvider('roles')]
    public function test_ranking_contest_deductions_change_final_ranks_without_redistributing_base_points(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event, $competition, $teamA, $teamB, $teamC] = $this->rankingContest();
        $parameters = [$event, $competition->category, $competition];
        $route = route('events.categories.competitions.deductions.update', $parameters);

        $this->actingAs($user)->patch($route, ['deductions' => [$teamA->id => 40]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $calculator = app(LeaderboardCalculator::class);
        $standings = $calculator->competitionStandings($competition->fresh());
        $this->assertSame(['TEAM B', 'TEAM A', 'TEAM C'], $standings->pluck('participant.name')->all());
        $this->assertSame([1, 2, 3], $standings->pluck('rank')->all());
        $this->assertSame([2, 1, 3], $standings->pluck('placement')->all());
        $this->assertSame([80.0, 100.0, 50.0], $standings->pluck('rank_points')->all());
        $this->assertSame([80.0, 60.0, 50.0], $standings->pluck('leaderboard_points')->all());
        $this->assertSame($standings->pluck('leaderboard_points')->all(), $calculator->competitionStandings($competition->fresh())->pluck('leaderboard_points')->all());

        $page = $this->get(route('events.categories.competitions.scores.edit', $parameters));
        $page->assertSee('value="1"', false)->assertSee('points after deductions');
        $this->assertSame('2', $this->xpath($page->getContent())->query('//tbody[@id="competition-score-rows"]/tr[td//input[@name="placements['.$teamA->id.']"]]/@data-rank')->item(0)->textContent);
        $this->patch(route('events.categories.competitions.scores.finalization.update', $parameters))->assertRedirect()->assertSessionHasNoErrors();
        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('categories.0.contests.0.rows.0.name', 'TEAM B')
            ->assertJsonPath('categories.0.contests.0.rows.0.rank', 1);
        $overall = $calculator->overallStandings($event->fresh())->keyBy('participant.id');
        $this->assertSame(1, $overall->get($teamB->id)['contest_wins']);
        $this->assertSame(0, $overall->get($teamA->id)['contest_wins']);

        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $this->actingAs($admin)->patch($route, ['deductions' => [$teamA->id => 100]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0.0, $calculator->competitionStandings($competition->fresh())->last()['leaderboard_points']);
        $this->patch($route, ['deductions' => [$teamA->id => 100.01]])->assertSessionHasErrors('deductions.'.$teamA->id);
        $this->patch($route, ['deductions' => [$teamA->id => 0]])->assertRedirect()->assertSessionHasNoErrors();
        $restored = $calculator->competitionStandings($competition->fresh());
        $this->assertSame(['TEAM A', 'TEAM B', 'TEAM C'], $restored->pluck('participant.name')->all());
        $this->assertSame([100.0, 80.0, 50.0], $restored->pluck('leaderboard_points')->all());
        $this->assertDatabaseHas('competition_results', ['competition_id' => $competition->id, 'participant_id' => $teamA->id, 'placement' => 1]);
    }

    #[DataProvider('adjustedPointTies')]
    public function test_ranking_contests_apply_the_event_tie_rule_to_points_after_deductions(TieRankingMethod $tieMethod, array $deductions, array $expectedPoints, array $expectedRanks): void
    {
        [$event, $competition, $teamA, $teamB, $teamC] = $this->rankingContest();
        $event->update(['tie_ranking_method' => $tieMethod]);

        foreach ([$teamA, $teamB, $teamC] as $index => $participant) {
            $competition->results()->where('participant_id', $participant->id)->firstOrFail()->update(['deduction' => $deductions[$index]]);
        }

        $standings = app(LeaderboardCalculator::class)->competitionStandings($competition->fresh());

        $this->assertSame($expectedPoints, $standings->pluck('leaderboard_points')->all());
        $this->assertSame($expectedRanks, $standings->pluck('rank')->all());
        $this->assertSame([100.0, 80.0, 50.0], $standings->pluck('rank_points')->all());
    }

    public function test_ranking_exports_use_final_point_ranks_and_preserve_entered_placements(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $competition, $teamA] = $this->rankingContest();
        $competition->results()->where('participant_id', $teamA->id)->firstOrFail()->update(['deduction' => 40]);
        $parameters = [$event, $competition->category, $competition];

        $excel = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'xlsx']))->assertOk();
        $pdf = $this->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'pdf']))->assertOk();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'test-adjusted-ranks-');
        $this->assertNotFalse($temporaryPath);

        try {
            file_put_contents($temporaryPath, $excel->getContent());
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($temporaryPath));
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $archive->close();
            $this->assertIsString($sheet);
        } finally {
            unlink($temporaryPath);
        }

        $this->assertStringContainsString('<c r="A10"><v>1</v></c><c r="B10" t="inlineStr"><is><t xml:space="preserve">TEAM B</t>', $sheet);
        $this->assertStringContainsString('<c r="D10"><v>2</v></c>', $sheet);
        $this->assertStringContainsString('<c r="F10"><v>80</v></c><c r="G10"><v>80</v></c>', $sheet);
        $this->assertStringContainsString('<c r="A11"><v>2</v></c><c r="B11" t="inlineStr"><is><t xml:space="preserve">TEAM A</t>', $sheet);
        $this->assertStringContainsString('<c r="D11"><v>1</v></c>', $sheet);
        $this->assertStringContainsString('<c r="F11"><v>100</v></c><c r="G11"><v>60</v></c>', $sheet);
        $this->assertTrue(strpos($pdf->getContent(), '(TEAM B)') < strpos($pdf->getContent(), '(TEAM A)'));
    }

    public static function roles(): array
    {
        return ['admin' => [AccountRole::Admin], 'auditor' => [AccountRole::Auditor]];
    }

    public static function staffLeaderboardStates(): array
    {
        return [
            'admin live' => [AccountRole::Admin, false], 'admin frozen' => [AccountRole::Admin, true],
            'auditor live' => [AccountRole::Auditor, false], 'auditor frozen' => [AccountRole::Auditor, true],
        ];
    }

    public static function adjustedPointTies(): array
    {
        return [
            'skip positions' => [TieRankingMethod::SkipPositions, [20, 0, 0], [80.0, 80.0, 50.0], [1, 1, 3]],
            'continue positions' => [TieRankingMethod::ConsecutivePositions, [20, 0, 0], [80.0, 80.0, 50.0], [1, 1, 2]],
            'decimal skip' => [TieRankingMethod::SkipPositions, [33.33, 13.33, 0], [66.67, 66.67, 50.0], [1, 1, 3]],
            'decimal continue' => [TieRankingMethod::ConsecutivePositions, [33.33, 13.33, 0], [66.67, 66.67, 50.0], [1, 1, 2]],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    /** @return array{Event, Participant, Participant} */
    private function overallEvent(): array
    {
        $event = Event::factory()->create();
        $teamA = $event->participants()->create(['name' => 'TEAM A']);
        $teamB = $event->participants()->create(['name' => 'TEAM B', 'overall_deduction' => 10]);
        $category = $event->categories()->create(['name' => 'Sports']);

        foreach ([['Low points 1', $teamA, 20], ['Low points 2', $teamA, 20], ['High points', $teamB, 100]] as [$name, $participant, $points]) {
            $competition = $category->competitions()->create(['name' => $name, 'scoring_method' => CompetitionScoringMethod::Wins, 'scores_finalized_at' => now()]);
            $competition->rankScores()->create(['rank' => 1, 'points' => $points]);
            $competition->results()->create(['participant_id' => $participant->id, 'wins' => 1]);
        }

        return [$event, $teamA, $teamB];
    }

    /** @return array{Event, Competition, Participant, Participant, Participant} */
    private function rankingContest(): array
    {
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Race', 'scoring_method' => CompetitionScoringMethod::Ranking]);
        $participants = [];

        foreach (['TEAM A', 'TEAM B', 'TEAM C'] as $index => $name) {
            $participant = $event->participants()->create(['name' => $name]);
            $participants[] = $participant;
            $competition->results()->create(['participant_id' => $participant->id, 'placement' => $index + 1]);
        }

        $excluded = $event->participants()->create(['name' => 'No entry']);
        $competition->results()->create(['participant_id' => $excluded->id, 'has_entry' => false]);
        $competition->rankScores()->createMany([['rank' => 1, 'points' => 100], ['rank' => 2, 'points' => 80], ['rank' => 3, 'points' => 50]]);

        return [$event, $competition, ...$participants];
    }
}
