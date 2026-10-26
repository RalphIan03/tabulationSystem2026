<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\Competition;
use App\Models\Event;
use App\Models\Participant;
use App\Models\User;
use App\Services\LeaderboardCalculator;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

final class OverallResultsReportTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_staff_see_finalized_and_pending_points_while_public_results_exclude_pending_scores(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event, $pending, $teamA, $teamB] = $this->scoredEvent();
        $standings = app(LeaderboardCalculator::class)->overallStandings($event->fresh());

        $this->assertSame([100.0, 60.0], $standings->pluck('finalized_points')->all());
        $this->assertSame([35.0, 20.0], $standings->pluck('pending_points')->all());
        $this->assertSame([125.0, 80.0], $standings->pluck('leaderboard_points')->all());
        URL::forceScheme('http');
        $page = $this->actingAs($user)->get('https://tabulation.example'.route('events.leaderboard', $event, false));
        $xpath = $this->xpath($page->getContent());
        $cells = $xpath->query('//table[@id="overall-standings-table"]/tbody/tr[1]/td');
        $this->assertSame('100.00', trim($cells->item($cells->length - 3)->textContent));
        $this->assertSame('35.00', trim($cells->item($cells->length - 2)->textContent));
        $this->assertSame('125.00', trim($cells->item($cells->length - 1)->textContent));
        $page->assertSee('Pending points')->assertSee('data-loading-text="Exporting overall PDF report…"', false);

        foreach (['pdf', 'xlsx'] as $format) {
            $page->assertSee('href="'.route('events.results.export', [$event, 'format' => $format], false).'" download data-export-download', false);
        }

        $this->getJson(route('leaderboards.data', $event))
            ->assertJsonPath('rows.0.contest_wins', 1)
            ->assertJsonMissingPath('rows.0.pending_points')
            ->assertJsonMissingPath('rows.0.finalized_points')
            ->assertJsonMissingPath('rows.0.points');
        $event->update(['leaderboard_frozen' => true]);
        $this->getJson(route('leaderboards.data', $event))->assertJsonPath('rows.0.points', 90)->assertJsonPath('rows.1.points', 60)->assertJsonMissingPath('rows.0.pending_points');
    }

    public function test_finalizing_and_reopening_moves_points_between_columns_without_double_counting(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $pending] = $this->scoredEvent();
        $this->getJson(route('leaderboards.data', $event))->assertJsonPath('rows.0.contest_wins', 1);
        $parameters = [$event, $pending->category, $pending];

        $this->actingAs($admin)->patch(route('events.categories.competitions.scores.finalization.update', $parameters))->assertRedirect()->assertSessionHasNoErrors();

        $finalized = app(LeaderboardCalculator::class)->overallStandings($event->fresh());
        $this->assertSame([135.0, 80.0], $finalized->pluck('finalized_points')->all());
        $this->assertSame([0.0, 0.0], $finalized->pluck('pending_points')->all());
        $this->assertSame([125.0, 80.0], $finalized->pluck('leaderboard_points')->all());
        $this->getJson(route('leaderboards.data', $event))->assertJsonPath('rows.0.contest_wins', 2);

        $this->patch(route('events.categories.competitions.scores.finalization.update', $parameters))->assertRedirect();
        $reopened = app(LeaderboardCalculator::class)->overallStandings($event->fresh());
        $this->assertSame([35.0, 20.0], $reopened->pluck('pending_points')->all());
        $this->assertSame([125.0, 80.0], $reopened->pluck('leaderboard_points')->all());
        $this->getJson(route('leaderboards.data', $event))->assertJsonPath('rows.0.contest_wins', 1);
    }

    public function test_incomplete_judge_scores_do_not_award_pending_points_until_all_judges_are_complete(): void
    {
        [$event, $pending, $teamA] = $this->scoredEvent();
        $criteriaContest = $pending->category->competitions()->create(['name' => 'Judged contest', 'judge_count' => 2]);
        $criterion = $criteriaContest->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $criteriaContest->rankScores()->create(['rank' => 1, 'points' => 50]);
        $result = $criteriaContest->results()->create(['participant_id' => $teamA->id]);
        $result->criterionScores()->create(['criterion_id' => $criterion->id, 'judge_number' => 1, 'score' => 90]);
        $calculator = app(LeaderboardCalculator::class);

        $this->assertSame(35.0, $calculator->overallStandings($event->fresh())->first()['pending_points']);
        $result->criterionScores()->create(['criterion_id' => $criterion->id, 'judge_number' => 2, 'score' => 80]);

        $this->assertSame(85.0, $calculator->overallStandings($event->fresh())->first()['pending_points']);
        $this->getJson(route('leaderboards.data', $event))->assertJsonPath('rows.0.contest_wins', 1)->assertJsonMissingPath('rows.0.pending_points');
    }

    #[DataProvider('staffFormats')]
    public function test_admins_and_auditors_download_real_pdf_and_excel_overall_reports(AccountRole $role, string $format, string $mime): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event] = $this->scoredEvent();

        $response = $this->actingAs($user)->get(route('events.results.export', [$event, 'format' => $format]));

        $response->assertOk()->assertDownload('overall-results-event-games-'.$event->id.'.'.$format)
            ->assertHeader('Content-Type', $mime)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith($format === 'pdf' ? '%PDF-1.4' : 'PK', $response->getContent());
    }

    public function test_overall_excel_contains_point_breakdown_signatories_and_safe_text_cells(): void
    {
        $user = User::factory()->create();
        [$event, $pending, $teamA, $teamB] = $this->scoredEvent();
        $teamB->update(['name' => '=SUM(A1:A2) & <unsafe>']);

        $response = $this->actingAs($user)->get(route('events.results.export', [$event, 'format' => 'xlsx']))->assertOk();

        [$sheet, $workbook] = $this->workbookXml($response->getContent());
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($sheet));
        $this->assertStringContainsString('name="Overall results"', $workbook);
        $this->assertStringContainsString('Provisional - unfinished contests remain', $sheet);
        $this->assertStringContainsString('<c r="C10"><v>10</v></c><c r="D10"><v>90</v></c>', $sheet);
        $this->assertStringContainsString('Category point totals: Sports', $sheet);
        $this->assertStringContainsString('<c r="B15"><v>100</v></c><c r="C15"><v>35</v></c><c r="D15"><v>135</v></c>', $sheet);
        $this->assertStringContainsString('=SUM(A1:A2) &amp; &lt;unsafe&gt;', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('Chief Auditor', $sheet);
        $this->assertStringContainsString('Team signatures', $sheet);
        $this->assertStringContainsString('ySplit="9"', $sheet);
        $this->assertStringContainsString('autoFilter ref="A9:D11"', $sheet);
        $this->assertStringNotContainsString('TEAM A2</t>', $sheet);
    }

    public function test_overall_pdf_is_landscape_tabular_and_honors_the_team_signature_toggle(): void
    {
        $user = User::factory()->create();
        [$event] = $this->scoredEvent();

        $response = $this->actingAs($user)->get(route('events.results.export', [$event, 'format' => 'pdf']))->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('/MediaBox [0 0 842 595]', $content);
        $this->assertStringContainsString('(Overall results report)', $content);
        $this->assertStringContainsString('(Pending points)', $content);
        $this->assertStringContainsString('(35.00)', $content);
        $this->assertStringContainsString('(90.00)', $content);
        $this->assertStringContainsString('(Category point totals: Sports)', $content);
        $this->assertStringNotContainsString('(Current net points)', $content);
        $this->assertStringContainsString('(Chief Auditor)', $content);
        $this->assertStringContainsString('(Team signatures)', $content);
        $this->assertTrue(strpos($content, '(TEAM A)') < strpos($content, '(TEAM B)'));

        $event->update(['include_team_signatories' => false]);
        $withoutTeams = $this->get(route('events.results.export', [$event, 'format' => 'pdf']))->assertOk();
        $this->assertStringNotContainsString('(Team signatures)', $withoutTeams->getContent());
        $this->assertStringContainsString('(Chief Auditor)', $withoutTeams->getContent());
        $excel = $this->get(route('events.results.export', [$event, 'format' => 'xlsx']))->assertOk();
        [$sheet] = $this->workbookXml($excel->getContent());
        $this->assertStringNotContainsString('Team signatures', $sheet);
        $this->assertStringContainsString('Chief Auditor', $sheet);
    }

    public function test_frozen_reports_use_highest_current_net_points_first_and_finalized_reports_have_no_pending_points(): void
    {
        $user = User::factory()->create();
        [$event, $pending, $teamA] = $this->scoredEvent();
        $teamA->update(['overall_deduction' => 100]);
        $event->update(['leaderboard_frozen' => true]);
        $pending->update(['scores_finalized_at' => now()]);

        $response = $this->actingAs($user)->get(route('events.results.export', [$event, 'format' => 'xlsx']))->assertOk();

        [$sheet] = $this->workbookXml($response->getContent());
        $this->assertStringContainsString('TEAM B</t>', $sheet);
        $this->assertTrue(strpos($sheet, 'TEAM B</t>') < strpos($sheet, 'TEAM A</t>'));
        $this->assertStringContainsString('Status: Finalized | Finalized contests: 2 of 2', $sheet);
        $this->assertStringContainsString('<c r="D10"><v>80</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D11"><v>35</v></c>', $sheet);
        $this->assertStringContainsString('<c r="C15"><v>0</v></c>', $sheet);
    }

    public function test_large_overall_pdf_repeats_table_headers_across_pages(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['include_team_signatories' => false]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Race', 'scoring_method' => CompetitionScoringMethod::Wins, 'scores_finalized_at' => now()]);

        for ($index = 1; $index <= 30; $index++) {
            $participant = $event->participants()->create(['name' => sprintf('Participant %02d with a long team name for report pagination', $index)]);
            $competition->results()->create(['participant_id' => $participant->id, 'wins' => 31 - $index]);
        }

        $response = $this->actingAs($user)->get(route('events.results.export', [$event, 'format' => 'pdf']))->assertOk();

        $content = $response->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($content, 'Td (Participant) Tj ET'));
        $this->assertStringContainsString('Page 2 of', $content);
        $this->assertStringContainsString('/MediaBox [0 0 842 595]', $content);
    }

    #[DataProvider('formats')]
    public function test_reports_handle_empty_events_and_are_protected_from_guests(string $format): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $route = route('events.results.export', [$event, 'format' => $format]);

        $this->get($route)->assertRedirect(route('login'));
        $response = $this->actingAs($user)->get($route)->assertOk();

        $this->assertStringStartsWith($format === 'pdf' ? '%PDF-1.4' : 'PK', $response->getContent());
    }

    #[DataProvider('formats')]
    public function test_admins_can_download_archived_overall_reports_but_auditors_cannot(string $format): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event] = $this->scoredEvent();
        $event->update(['archived_at' => now(), 'is_active' => false]);
        $route = route('events.results.export', [$event, 'format' => $format]);

        $this->actingAs($admin)->get($route)->assertOk()->assertDownload();
        $this->get(route('events.show', $event))->assertSee('Overall Excel')->assertSee('Overall PDF');
        $this->actingAs($auditor)->get($route)->assertNotFound();
    }

    public function test_unsupported_report_formats_are_rejected(): void
    {
        $user = User::factory()->create();
        [$event] = $this->scoredEvent();

        $this->actingAs($user)->get(route('events.results.export', [$event, 'format' => 'html']))->assertNotFound();
    }

    public function test_category_totals_sum_contest_points_and_linked_teams_without_reapplying_event_deductions(): void
    {
        $user = User::factory()->create();
        [$event, $pending, $teamA, $teamB] = $this->scoredEvent();
        $arts = $event->categories()->create(['name' => 'Arts']);
        $contest = $arts->competitions()->create(['name' => 'Dance', 'scoring_method' => CompetitionScoringMethod::Wins, 'scores_finalized_at' => now()]);
        $contest->rankScores()->createMany([['rank' => 1, 'points' => 30], ['rank' => 2, 'points' => 15]]);
        $contest->results()->createMany([['participant_id' => $teamB->id, 'wins' => 2], ['participant_id' => $teamA->id, 'wins' => 1, 'deduction' => 5]]);
        $event->categories()->create(['name' => 'Empty category']);
        $otherEvent = Event::factory()->create(['name' => 'Unrelated secret event']);
        $otherEvent->categories()->create(['name' => 'Secret category']);

        $response = $this->actingAs($user)->get(route('events.results.export', [$event, 'format' => 'xlsx']))->assertOk();

        [$sheet] = $this->workbookXml($response->getContent());
        $rows = $this->sheetRows($sheet);
        $this->assertSame(['Rank', 'Participant', 'Overall deduction', 'Finalized points after deduction'], $rows[8]);
        $this->assertSame(['1', 'TEAM A', '10', '100'], $rows[9]);
        $this->assertSame(['2', 'TEAM B', '0', '90'], $rows[10]);
        $artsHeading = array_search(['Category point totals: Arts'], $rows, true);
        $sportsHeading = array_search(['Category point totals: Sports'], $rows, true);
        $this->assertNotFalse($artsHeading);
        $this->assertNotFalse($sportsHeading);
        $this->assertSame(['TEAM B', '30', '0', '30'], $rows[$artsHeading + 2]);
        $this->assertSame(['TEAM A', '10', '0', '10'], $rows[$artsHeading + 3]);
        $this->assertSame(['TEAM A', '100', '35', '135'], $rows[$sportsHeading + 2]);
        $this->assertSame(['TEAM B', '60', '20', '80'], $rows[$sportsHeading + 3]);
        $this->assertStringContainsString('Category point totals: Empty category', $sheet);
        $this->assertStringNotContainsString('Secret category', $sheet);
        $this->assertStringNotContainsString('TEAM A2</t>', $sheet);
    }

    public function test_summary_ranking_ignores_pending_wins_and_clamps_finalized_points_after_deduction(): void
    {
        $user = User::factory()->create();
        [$event, $pending, $teamA, $teamB] = $this->scoredEvent();
        $teamA->update(['overall_deduction' => 150]);
        $teamC = $event->participants()->create(['name' => 'Pending only', 'overall_deduction' => 10]);
        $pending->results()->create(['participant_id' => $teamC->id, 'wins' => 5]);
        $pending->results()->where('participant_id', $teamB->id)->update(['wins' => 6]);
        $event->update(['leaderboard_frozen' => true]);

        $response = $this->actingAs($user)->get(route('events.results.export', [$event, 'format' => 'xlsx']))->assertOk();

        [$sheet] = $this->workbookXml($response->getContent());
        $rows = $this->sheetRows($sheet);
        $this->assertSame(['1', 'TEAM B', '0', '60'], $rows[9]);
        $this->assertSame(['2', 'TEAM A', '150', '0'], $rows[10]);
        $this->assertSame(['', 'Pending only', '10', '0'], $rows[11]);
        $this->assertStringNotContainsString('Current net points', $sheet);
    }

    #[DataProvider('staffFormats')]
    public function test_staff_download_all_event_results_and_event_page_has_relative_download_buttons(AccountRole $role, string $format, string $mime): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event] = $this->scoredEvent();
        URL::forceScheme('http');
        $path = route('events.results.export-all', [$event, 'format' => $format], false);

        $page = $this->actingAs($user)->get('https://tabulation.example'.route('events.show', $event, false));

        $page->assertSee('href="'.$path.'" download data-export-download', false)->assertSee('All results PDF')->assertSee('All results Excel');
        $response = $this->get('https://tabulation.example'.$path)->assertOk()
            ->assertDownload('all-results-event-games-'.$event->id.'.'.$format)
            ->assertHeader('Content-Type', $mime)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith($format === 'pdf' ? '%PDF-1.4' : 'PK', $response->getContent());
    }

    #[DataProvider('formats')]
    public function test_all_results_include_ordered_contests_individual_judges_and_signatures_at_the_end(string $format): void
    {
        $user = User::factory()->create();
        [$event, $pending, $teamA, $teamB] = $this->scoredEvent();
        $contest = $pending->category->competitions()->create(['name' => 'Z judged contest', 'judge_count' => 2]);
        $criterion = $contest->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $contest->rankScores()->create(['rank' => 1, 'points' => 75]);
        $result = $contest->results()->create(['participant_id' => $teamA->id, 'deduction' => 2]);
        $result->criterionScores()->createMany([
            ['criterion_id' => $criterion->id, 'judge_number' => 1, 'score' => 90],
            ['criterion_id' => $criterion->id, 'judge_number' => 2, 'score' => 80],
        ]);
        $contest->results()->create(['participant_id' => $teamB->id, 'has_entry' => false]);
        $pending->category->competitions()->create(['name' => 'Unstarted contest', 'scoring_method' => CompetitionScoringMethod::Wins]);

        $response = $this->actingAs($user)->get(route('events.results.export-all', [$event, 'format' => $format]))->assertOk();

        $content = $response->getContent();

        if ($format === 'xlsx') {
            [$content, $workbook] = $this->workbookXml($content);
            $this->assertStringContainsString('name="All results"', $workbook);
            $rows = $this->sheetRows($content);
            $judge = array_search(['Sports / Z judged contest | Pending finalization | Judge 1 scores'], $rows, true);
            $this->assertNotFalse($judge);
            $this->assertSame(['Rank', 'Participant', 'Status', 'Technique', 'Judge total'], $rows[$judge + 1]);
            $this->assertSame(['1', 'TEAM A', 'Scored', '90', '90'], $rows[$judge + 2]);
            $this->assertSame(['', 'TEAM B', 'No entry', '0', '0'], $rows[$judge + 4]);
            $summary = array_search(['Sports / Z judged contest | Pending finalization | Averaged scores'], $rows, true);
            $this->assertNotFalse($summary);
            $this->assertSame(['1', 'TEAM A', 'Scored', '85', '85', '2', '83', '75'], $rows[$summary + 2]);
            $this->assertStringNotContainsString('<f>', $content);
        } else {
            $this->assertStringContainsString('/MediaBox [0 0 842 595]', $content);
            $this->assertStringContainsString('(85.00)', $content);
            $this->assertStringContainsString('(83.00)', $content);
        }

        $this->assertStringContainsString('Finalized race | Finalized', $content);
        $this->assertStringContainsString('Pending race | Pending finalization', $content);
        $this->assertStringContainsString('Unstarted contest | Not started', $content);
        $this->assertStringContainsString('Judge 1 scores', $content);
        $this->assertStringContainsString('Judge 2 scores', $content);
        $this->assertStringContainsString('Best on Technique', $content);
        $this->assertTrue(strpos($content, 'Finalized points after') < strpos($content, 'Finalized race | Finalized'));
        $this->assertTrue(strpos($content, 'Finalized race | Finalized') < strpos($content, 'Pending race | Pending finalization'));
        $this->assertTrue(strpos($content, 'Category point totals: Sports') < strpos($content, 'Chief Auditor'));
        $this->assertStringContainsString('Team signatures', $content);
    }

    #[DataProvider('formats')]
    public function test_all_results_downloads_preserve_archive_visibility_and_guest_protection(string $format): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event] = $this->scoredEvent();
        $event->update(['archived_at' => now(), 'is_active' => false]);
        $route = route('events.results.export-all', [$event, 'format' => $format]);

        $this->get($route)->assertRedirect(route('login'));
        $this->actingAs($admin)->get($route)->assertOk()->assertDownload();
        $this->get(route('events.show', $event))->assertSee('All results PDF')->assertSee('All results Excel');
        $this->actingAs($auditor)->get($route)->assertNotFound();
        $this->actingAs($admin)->get(route('events.results.export-all', [$event, 'format' => 'html']))->assertNotFound();
    }

    public static function roles(): array
    {
        return ['admin' => [AccountRole::Admin], 'auditor' => [AccountRole::Auditor]];
    }

    public static function formats(): array
    {
        return ['pdf' => ['pdf'], 'excel' => ['xlsx']];
    }

    public static function staffFormats(): array
    {
        $cases = [];

        foreach ([AccountRole::Admin, AccountRole::Auditor] as $role) {
            $cases[$role->value.' pdf'] = [$role, 'pdf', 'application/pdf'];
            $cases[$role->value.' excel'] = [$role, 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        return $cases;
    }

    /** @return array{Event, Competition, Participant, Participant} */
    private function scoredEvent(): array
    {
        $event = Event::factory()->create(['name' => 'Event Games', 'report_signatories' => "Chief Auditor\nEvent Head", 'include_team_signatories' => true]);
        $teamA = $event->participants()->create(['name' => 'TEAM A', 'overall_deduction' => 10]);
        $teamB = $event->participants()->create(['name' => 'TEAM B']);
        $linked = $event->participants()->create(['name' => 'TEAM A2', 'parent_participant_id' => $teamA->id]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $finalized = $category->competitions()->create(['name' => 'Finalized race', 'scoring_method' => CompetitionScoringMethod::Ranking, 'scores_finalized_at' => now()]);
        $finalized->rankScores()->createMany([['rank' => 1, 'points' => 100], ['rank' => 2, 'points' => 60]]);
        $finalized->results()->createMany([['participant_id' => $teamA->id, 'placement' => 1], ['participant_id' => $teamB->id, 'placement' => 2]]);
        $pending = $category->competitions()->create(['name' => 'Pending race', 'scoring_method' => CompetitionScoringMethod::Wins]);
        $pending->rankScores()->createMany([['rank' => 1, 'points' => 40], ['rank' => 2, 'points' => 20]]);
        $pending->results()->createMany([['participant_id' => $linked->id, 'wins' => 3, 'deduction' => 5], ['participant_id' => $teamB->id, 'wins' => 2]]);

        return [$event, $pending, $teamA, $teamB];
    }

    /** @return array{string, string} */
    private function workbookXml(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test-overall-report-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $content);
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path));
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $workbook = $archive->getFromName('xl/workbook.xml');
            $archive->close();
            $this->assertIsString($sheet);
            $this->assertIsString($workbook);

            return [$sheet, $workbook];
        } finally {
            unlink($path);
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    /** @return list<list<string>> */
    private function sheetRows(string $sheet): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($sheet));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];

        foreach ($xpath->query('//s:sheetData/s:row') as $row) {
            $values = [];

            foreach ($xpath->query('s:c', $row) as $cell) {
                $values[] = $cell->textContent;
            }

            $rows[] = $values;
        }

        return $rows;
    }
}
