<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Criterion;
use App\Models\Event;
use App\Models\Participant;
use App\Models\User;
use App\Services\LeaderboardCalculator;
use App\Services\PublicLeaderboardData;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MultiJudgeScoringTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_each_judge_can_be_saved_separately_before_overall_deductions_and_points(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event, $category, $competition, $participant, $technique, $presentation] = $this->contest();
        $competition->rankScores()->create(['rank' => 1, 'points' => 50]);
        $parameters = [$event, $category, $competition];
        $route = route('events.categories.competitions.scores.update', $parameters);

        $this->actingAs($user)->patch($route, [
            'judge_number' => 1,
            'scores' => [$participant->id => [$technique->id => [1 => 80], $presentation->id => [1 => 70]]],
        ])->assertRedirect()->assertSessionHas('status', 'Judge 1 scores saved successfully.');

        $this->assertDatabaseCount('criterion_scores', 2);
        $this->assertCount(0, app(LeaderboardCalculator::class)->competitionStandings($competition->fresh()));
        $pending = $this->get(route('events.categories.competitions.scores.edit', $parameters))->assertOk();
        $pendingCells = $this->overallCells($pending->getContent());
        $this->assertSame('150.00', $pendingCells[3]);
        $this->assertSame('Pending', $pendingCells[4]);
        $this->assertSame('Pending', $pendingCells[5]);
        $this->assertSame('Save score first', $pendingCells[6]);
        $this->patch(route('events.categories.competitions.scores.finalization.update', $parameters))
            ->assertSessionHasErrors('finalization');

        $this->patch($route, [
            'judge_number' => 2,
            'scores' => [$participant->id => [$technique->id => [2 => 100], $presentation->id => [2 => 90]]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('criterion_scores', 4);
        $this->assertDatabaseHas('criterion_scores', ['criterion_id' => $technique->id, 'judge_number' => 1, 'score' => 80]);
        $this->patch(route('events.categories.competitions.deductions.update', $parameters), [
            'deductions' => [$participant->id => 20],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $page = $this->get(route('events.categories.competitions.scores.edit', $parameters))
            ->assertSeeInOrder(['Overall scores', 'Judge 1 scores', 'Judge 2 scores']);
        $cells = $this->overallCells($page->getContent());
        $this->assertSame('#1', $cells[0]);
        $this->assertSame('150.00', $cells[3]);
        $this->assertSame('190.00', $cells[4]);
        $this->assertSame('170.00', $cells[5]);
        $this->assertSame('150.00', $cells[7]);
        $this->assertSame('50.00', $cells[8]);

        $xpath = $this->xpath($page->getContent());
        $this->assertSame(1, $xpath->query('//table[@id="overall-score-table"]//input[starts-with(@name,"deductions[")]')->length);
        $this->assertSame(0, $xpath->query('//form[starts-with(@id,"judge-")]//input[starts-with(@name,"deductions[")]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="judge-1-scores"]//input[@name="judge_number" and @value="1"]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="judge-2-scores"]//input[@name="judge_number" and @value="2"]')->length);
    }

    public function test_equal_judge_totals_have_equal_averages_rankings_and_points_before_deductions(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $category, $competition, $teamA, $technique, $presentation] = $this->contest();
        $competition->update(['judge_count' => 3]);
        $creativity = $competition->criteria()->create(['name' => 'Creativity', 'max_score' => 100]);
        $teamB = $event->participants()->create(['name' => 'TEAM B']);
        $competition->rankScores()->createMany([
            ['rank' => 1, 'points' => 50],
            ['rank' => 2, 'points' => 30],
        ]);
        $parameters = [$event, $category, $competition];
        $judgeScores = [
            1 => [[$technique->id => 33, $presentation->id => 33, $creativity->id => 23], [$technique->id => 34, $presentation->id => 34, $creativity->id => 22]],
            2 => [[$technique->id => 33, $presentation->id => 33, $creativity->id => 23], [$technique->id => 34, $presentation->id => 34, $creativity->id => 22]],
            3 => [[$technique->id => 34, $presentation->id => 34, $creativity->id => 22], [$technique->id => 33, $presentation->id => 33, $creativity->id => 22]],
        ];

        foreach ($judgeScores as $judge => [$scoresA, $scoresB]) {
            $this->actingAs($admin)->patch(route('events.categories.competitions.scores.update', $parameters), [
                'judge_number' => $judge,
                'scores' => [
                    $teamA->id => array_map(static fn (int $score): array => [$judge => $score], $scoresA),
                    $teamB->id => array_map(static fn (int $score): array => [$judge => $score], $scoresB),
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $standings = app(LeaderboardCalculator::class)->competitionStandings($competition->fresh(), $event);
        $this->assertSame(['TEAM A', 'TEAM B'], $standings->pluck('participant.name')->all());
        $this->assertSame([89.33, 89.33], $standings->pluck('gross_score')->all());
        $this->assertSame([1, 1], $standings->pluck('rank')->all());
        $this->assertSame([50.0, 50.0], $standings->pluck('leaderboard_points')->all());
        $page = $this->get(route('events.categories.competitions.scores.edit', $parameters));
        $averages = $this->xpath($page->getContent())->query('//table[@id="overall-score-table"]/tbody/tr/td[7]');
        $this->assertSame(['89.33', '89.33'], array_map(static fn ($cell): string => trim($cell->textContent), iterator_to_array($averages)));

        $this->patch(route('events.categories.competitions.scores.finalization.update', $parameters))
            ->assertRedirect()->assertSessionHasNoErrors();
        Cache::put("leaderboard:event:{$event->id}:snapshot:v4", ['stale_calculation' => true], 120);
        $snapshot = app(PublicLeaderboardData::class)->snapshot($event->fresh());
        $this->assertArrayNotHasKey('stale_calculation', $snapshot);
        $this->assertSame([1, 1], array_column($snapshot['categories'][0]['contests'][0]['rows'], 'rank'));

        $this->patch(route('events.categories.competitions.deductions.update', $parameters), [
            'deductions' => [$teamB->id => 0.5],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $deducted = app(LeaderboardCalculator::class)->competitionStandings($competition->fresh(), $event);
        $this->assertSame([89.33, 89.33], $deducted->pluck('gross_score')->all());
        $this->assertSame([89.33, 88.83], $deducted->pluck('raw_score')->all());
        $this->assertSame([1, 2], $deducted->pluck('rank')->all());
        $this->assertSame([50.0, 30.0], $deducted->pluck('leaderboard_points')->all());
        $this->assertDatabaseCount('criterion_scores', 18);
    }

    #[DataProvider('decimalJudgeScores')]
    public function test_decimal_judge_scores_are_averaged_before_rounding_the_overall_total(array $techniqueScores, array $presentationScores, float $expected): void
    {
        [$event, $category, $competition, $participant, $technique, $presentation] = $this->contest();
        $competition->update(['judge_count' => count($techniqueScores)]);
        $result = $competition->results()->create(['participant_id' => $participant->id]);

        foreach ($techniqueScores as $index => $score) {
            $result->criterionScores()->createMany([
                ['criterion_id' => $technique->id, 'judge_number' => $index + 1, 'score' => $score],
                ['criterion_id' => $presentation->id, 'judge_number' => $index + 1, 'score' => $presentationScores[$index]],
            ]);
        }

        $standings = app(LeaderboardCalculator::class)->competitionStandings($competition->fresh(), $event);

        $this->assertSame($expected, $standings->sole()['gross_score']);
    }

    public function test_editing_or_clearing_one_judge_preserves_other_judges_and_participants(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event, $category, $competition, $participant, $technique, $presentation] = $this->contest();
        $result = $competition->results()->create(['participant_id' => $participant->id, 'deduction' => 10]);
        $result->criterionScores()->createMany([
            ['criterion_id' => $technique->id, 'judge_number' => 1, 'score' => 80],
            ['criterion_id' => $presentation->id, 'judge_number' => 1, 'score' => 70],
            ['criterion_id' => $technique->id, 'judge_number' => 2, 'score' => 90],
            ['criterion_id' => $presentation->id, 'judge_number' => 2, 'score' => 80],
        ]);
        $otherParticipant = $event->participants()->create(['name' => 'TEAM B']);
        $otherResult = $competition->results()->create(['participant_id' => $otherParticipant->id]);
        $otherScore = $otherResult->criterionScores()->create(['criterion_id' => $technique->id, 'judge_number' => 1, 'score' => 66]);

        $this->actingAs($auditor)->patch(route('events.categories.competitions.scores.update', [$event, $category, $competition]), [
            'judge_number' => 1,
            'scores' => [$participant->id => [$technique->id => [1 => 75], $presentation->id => [1 => null]]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('criterion_scores', ['competition_result_id' => $result->id, 'criterion_id' => $technique->id, 'judge_number' => 1, 'score' => 75]);
        $this->assertDatabaseMissing('criterion_scores', ['competition_result_id' => $result->id, 'criterion_id' => $presentation->id, 'judge_number' => 1]);
        $this->assertDatabaseHas('criterion_scores', ['competition_result_id' => $result->id, 'criterion_id' => $technique->id, 'judge_number' => 2, 'score' => 90]);
        $this->assertDatabaseHas('criterion_scores', ['competition_result_id' => $result->id, 'criterion_id' => $presentation->id, 'judge_number' => 2, 'score' => 80]);
        $this->assertModelExists($otherScore);
        $this->assertSame('10.00', $result->refresh()->deduction);
        $this->assertTrue($result->has_entry);
    }

    public function test_a_judge_submission_cannot_write_another_judges_scores_or_change_entries(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $category, $competition, $participant, $criterion] = $this->contest();
        $route = route('events.categories.competitions.scores.update', [$event, $category, $competition]);

        $this->actingAs($admin)->patch($route, [
            'judge_number' => 1,
            'scores' => [$participant->id => [$criterion->id => [1 => 80, 2 => 99]]],
        ])->assertSessionHasErrors("scores.{$participant->id}.{$criterion->id}");
        $this->patch($route, [
            'judge_number' => 1,
            'entries' => [$participant->id => false],
            'scores' => [$participant->id => [$criterion->id => [1 => 80]]],
        ])->assertSessionHasErrors('entries');

        $this->assertDatabaseCount('criterion_scores', 0);
        $this->assertDatabaseCount('competition_results', 0);
    }

    #[DataProvider('invalidJudgeNumbers')]
    public function test_judge_submission_rejects_invalid_judge_numbers(mixed $judgeNumber): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $category, $competition, $participant, $criterion] = $this->contest();

        $this->actingAs($admin)->patch(route('events.categories.competitions.scores.update', [$event, $category, $competition]), [
            'judge_number' => $judgeNumber,
            'scores' => [$participant->id => [$criterion->id => [1 => 80]]],
        ])->assertSessionHasErrors('judge_number');

        $this->assertDatabaseCount('criterion_scores', 0);
    }

    public function test_judge_submission_validates_criterion_maximum_and_event_participants(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $category, $competition, $participant, $criterion] = $this->contest();
        $otherEvent = Event::factory()->create();
        $outsideParticipant = $otherEvent->participants()->create(['name' => 'Outside team']);
        $route = route('events.categories.competitions.scores.update', [$event, $category, $competition]);

        $this->actingAs($admin)->patch($route, [
            'judge_number' => 1,
            'scores' => [$participant->id => [$criterion->id => [1 => 101]]],
        ])->assertSessionHasErrors(["scores.{$participant->id}.{$criterion->id}.1" => 'A judge score cannot exceed the criterion maximum.']);
        $this->patch($route, [
            'judge_number' => 1,
            'scores' => [$outsideParticipant->id => [$criterion->id => [1 => 80]]],
        ])->assertSessionHasErrors('scores');

        $this->assertDatabaseCount('criterion_scores', 0);
    }

    #[DataProvider('roles')]
    public function test_overall_entry_changes_preserve_scores_and_deductions(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event, $category, $competition, $participant, $criterion] = $this->contest();
        $result = $competition->results()->create(['participant_id' => $participant->id, 'deduction' => 5]);
        $score = $result->criterionScores()->create(['criterion_id' => $criterion->id, 'judge_number' => 2, 'score' => 90]);
        $parameters = [$event, $category, $competition];

        $this->actingAs($user)->patch(route('events.categories.competitions.entries.update', $parameters), [
            'entries' => [$participant->id => false],
        ])->assertRedirect()->assertSessionHas('status', 'Participant entries saved successfully.');

        $this->assertFalse($result->refresh()->has_entry);
        $this->assertSame('5.00', $result->deduction);
        $this->assertModelExists($score);
        $this->patch(route('events.categories.competitions.scores.update', $parameters), [
            'judge_number' => 2,
            'scores' => [$participant->id => [$criterion->id => [2 => 50]]],
        ])->assertRedirect();
        $this->assertSame('90.00', $score->refresh()->score);

        $this->patch(route('events.categories.competitions.entries.update', $parameters), [
            'entries' => [$participant->id => true],
        ])->assertRedirect();
        $this->assertTrue($result->refresh()->has_entry);
        $this->assertModelExists($score);
    }

    public function test_overall_entries_can_exclude_all_linked_subteams_without_changing_main_teams(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event, $category, $competition, $team, $criterion] = $this->contest();
        $linked = $event->participants()->create(['name' => 'TEAM A2', 'parent_participant_id' => $team->id]);
        $result = $competition->results()->create(['participant_id' => $linked->id]);
        $score = $result->criterionScores()->create(['criterion_id' => $criterion->id, 'judge_number' => 1, 'score' => 80]);

        $this->actingAs($auditor)->patch(route('events.categories.competitions.entries.update', [$event, $category, $competition]), [
            'entries' => [$team->id => true, $linked->id => true],
            'exclude_subteams' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($result->refresh()->has_entry);
        $this->assertModelExists($score);
        $this->assertDatabaseHas('competition_results', ['competition_id' => $competition->id, 'participant_id' => $team->id, 'has_entry' => true]);
    }

    public function test_finalized_judge_and_entry_changes_are_locked_for_auditors_but_allow_admin_override(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $category, $competition, $participant, $criterion] = $this->contest();
        $competition->update(['scores_finalized_at' => now()]);
        $parameters = [$event, $category, $competition];
        $payload = ['judge_number' => 1, 'scores' => [$participant->id => [$criterion->id => [1 => 80]]]];

        $this->actingAs($auditor)->patch(route('events.categories.competitions.scores.update', $parameters), $payload)->assertForbidden();
        $this->patch(route('events.categories.competitions.entries.update', $parameters), ['entries' => [$participant->id => false]])->assertForbidden();
        $this->get(route('events.categories.competitions.scores.edit', $parameters))
            ->assertDontSee('Save Judge 1 scores')->assertDontSee('Save entries');
        $this->assertDatabaseCount('criterion_scores', 0);

        $this->actingAs($admin)->patch(route('events.categories.competitions.scores.update', $parameters), $payload)->assertRedirect();
        $this->patch(route('events.categories.competitions.entries.update', $parameters), ['entries' => [$participant->id => false]])->assertRedirect();

        $this->assertDatabaseCount('criterion_scores', 1);
        $this->assertDatabaseHas('competition_results', ['competition_id' => $competition->id, 'participant_id' => $participant->id, 'has_entry' => false]);
        $this->assertNotNull($competition->refresh()->scores_finalized_at);
    }

    public function test_overall_entries_require_authentication_and_scoped_event_participants(): void
    {
        [$event, $category, $competition, $participant] = $this->contest();
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $otherEvent = Event::factory()->create();
        $outsideParticipant = $otherEvent->participants()->create(['name' => 'Outside team']);
        $parameters = [$event, $category, $competition];
        $route = route('events.categories.competitions.entries.update', $parameters);

        $this->patch($route, ['entries' => [$participant->id => false]])->assertRedirect(route('login'));
        $this->actingAs($admin)->patch($route, ['entries' => [$outsideParticipant->id => false]])->assertSessionHasErrors('entries');
        $this->patch($route, ['entries' => [$participant->id => 'invalid']])->assertSessionHasErrors('entries.'.$participant->id);
        $this->patch(route('events.categories.competitions.entries.update', [$otherEvent, $category, $competition]), ['entries' => [$participant->id => false]])->assertNotFound();

        $this->assertDatabaseCount('competition_results', 0);
    }

    public function test_overall_entries_reject_single_judge_and_non_criteria_contests(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $category, $competition, $participant] = $this->contest();
        $competition->update(['judge_count' => 1]);

        $this->actingAs($admin)->patch(route('events.categories.competitions.entries.update', [$event, $category, $competition]), [
            'entries' => [$participant->id => false],
        ])->assertForbidden();
        $competition->update(['judge_count' => 2, 'scoring_method' => CompetitionScoringMethod::Wins]);
        $this->patch(route('events.categories.competitions.entries.update', [$event, $category, $competition]), [
            'entries' => [$participant->id => false],
        ])->assertForbidden();

        $this->assertDatabaseCount('competition_results', 0);
    }

    /** @return array<string, array{list<string>, list<string>, float}> */
    public static function decimalJudgeScores(): array
    {
        return [
            'repeating decimals' => [['10.01', '10.02', '10.02'], ['20.01', '20.02', '20.02'], 30.03],
            'half-cent rounds up' => [['10.01', '10.02'], ['20.00', '20.00'], 30.02],
        ];
    }

    /** @return array<string, array{AccountRole}> */
    public static function roles(): array
    {
        return ['admin' => [AccountRole::Admin], 'auditor' => [AccountRole::Auditor]];
    }

    /** @return array<string, array{mixed}> */
    public static function invalidJudgeNumbers(): array
    {
        return ['below range' => [0], 'above range' => [3], 'not an integer' => ['invalid'], 'empty' => [null]];
    }

    /** @return array{Event, Category, Competition, Participant, Criterion, Criterion} */
    private function contest(): array
    {
        $event = Event::factory()->create();
        $participant = $event->participants()->create(['name' => 'TEAM A']);
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting', 'judge_count' => 2]);
        $technique = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $presentation = $competition->criteria()->create(['name' => 'Presentation', 'max_score' => 100]);

        return [$event, $category, $competition, $participant, $technique, $presentation];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    /** @return list<string> */
    private function overallCells(string $html): array
    {
        $cells = $this->xpath($html)->query('//table[@id="overall-score-table"]/tbody/tr[1]/td');
        $this->assertSame(9, $cells->length);

        return array_map(static fn ($cell): string => trim(preg_replace('/\s+/u', ' ', $cell->textContent)), iterator_to_array($cells));
    }
}
