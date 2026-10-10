<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

final class CompetitionScoreExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_criteria_score_sheet_downloads_as_a_real_excel_workbook(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create(['name' => 'Regional Games']);
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create([
            'name' => 'Painting',
            'scores_finalized_at' => now(),
        ]);
        $technique = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 50]);
        $presentation = $competition->criteria()->create(['name' => 'Presentation', 'max_score' => 50]);
        $competition->rankScores()->create(['rank' => 1, 'points' => 100]);
        $competition->rankScores()->create(['rank' => 2, 'points' => 50]);
        $alice = $event->participants()->create(['name' => 'Alice Santos']);
        $bob = $event->participants()->create(['name' => 'Bob Cruz']);
        $zoe = $event->participants()->create(['name' => 'Zoe Low']);
        $event->participants()->create(['name' => 'Dane Pending']);
        $event->participants()->create(['name' => '=2+2 & <unsafe>']);
        $aliceResult = $competition->results()->create([
            'participant_id' => $alice->id,
            'deduction' => 2.5,
        ]);
        $aliceResult->criterionScores()->createMany([
            ['criterion_id' => $technique->id, 'score' => 45],
            ['criterion_id' => $presentation->id, 'score' => 40],
        ]);
        $competition->results()->create([
            'participant_id' => $bob->id,
            'has_entry' => false,
        ]);
        $zoeResult = $competition->results()->create(['participant_id' => $zoe->id]);
        $zoeResult->criterionScores()->createMany([
            ['criterion_id' => $technique->id, 'score' => 15],
            ['criterion_id' => $presentation->id, 'score' => 15],
        ]);
        $route = route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'xlsx',
        ]);

        $response = $this->actingAs($auditor)->get($route);

        $response->assertOk()
            ->assertDownload("scores-regional-games-painting-{$competition->id}.xlsx")
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Cache-Control', 'no-store, private');

        $content = $response->getContent();
        $this->assertStringStartsWith('PK', $content);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'test-score-xlsx-');
        $this->assertNotFalse($temporaryPath);

        try {
            file_put_contents($temporaryPath, $content);
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($temporaryPath));
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $archive->close();
            $this->assertIsString($sheet);
        } finally {
            unlink($temporaryPath);
        }

        $xml = simplexml_load_string($sheet);
        $this->assertNotFalse($xml);
        $this->assertStringContainsString('Regional Games', $sheet);
        $this->assertStringContainsString('Technique', $sheet);
        $this->assertStringContainsString('Presentation', $sheet);
        $this->assertStringContainsString('Alice Santos', $sheet);
        $this->assertStringContainsString('No entry', $sheet);
        $this->assertStringContainsString('Dane Pending', $sheet);
        $this->assertStringContainsString('=2+2 &amp; &lt;unsafe&gt;', $sheet);
        $this->assertStringNotContainsString('t="f"', $sheet);
        $this->assertStringContainsString('<c r="A9" t="inlineStr"><is><t xml:space="preserve">Averaged scores</t>', $sheet);
        $this->assertStringContainsString('<c r="A11"><v>1</v></c><c r="B11" t="inlineStr"><is><t xml:space="preserve">Alice Santos</t>', $sheet);
        $this->assertStringContainsString('<c r="A12"><v>2</v></c><c r="B12" t="inlineStr"><is><t xml:space="preserve">Zoe Low</t>', $sheet);
        $this->assertStringContainsString('<c r="I11"><v>100</v></c>', $sheet);
        $this->assertStringContainsString('<c r="H11"><v>82.5</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D14"><v>0</v></c><c r="E14"><v>0</v></c><c r="F14"><v>0</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D15"/><c r="E15"/>', $sheet);

        $this->actingAs($auditor)
            ->get(route('events.categories.competitions.scores.edit', [$event, $category, $competition]))
            ->assertOk()
            ->assertSee('Export Excel')
            ->assertSee('Export PDF')
            ->assertSee('data-export-download data-loading-text="Exporting Excel…"', false)
            ->assertSee('data-export-download data-loading-text="Exporting PDF…"', false);
    }

    public function test_wins_score_sheet_downloads_as_pdf_with_deductions(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['name' => 'Sports Meet']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
        ]);
        $competition->rankScores()->create(['rank' => 1, 'points' => 50]);
        $competition->rankScores()->create(['rank' => 2, 'points' => 25]);
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $lowerParticipant = $event->participants()->create(['name' => 'Aaron Low']);
        $competition->results()->create([
            'participant_id' => $participant->id,
            'wins' => 3,
            'deduction' => 1,
        ]);
        $competition->results()->create([
            'participant_id' => $lowerParticipant->id,
            'wins' => 1,
        ]);

        $response = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]));

        $response->assertOk()
            ->assertDownload("scores-sports-meet-basketball-{$competition->id}.pdf")
            ->assertHeader('Content-Type', 'application/pdf');

        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $content);
        $this->assertStringEndsWith('%%EOF', $content);
        $this->assertMatchesRegularExpression('/startxref\n(\d+)\n%%EOF$/', $content);
        preg_match('/startxref\n(\d+)\n%%EOF$/', $content, $matches);
        $this->assertSame('xref', substr($content, (int) $matches[1], 4));
        $this->assertStringContainsString('/MediaBox [0 0 842 595]', $content);
        $this->assertStringContainsString('Td (Rank) Tj ET', $content);
        $this->assertStringContainsString('Td (Participant) Tj ET', $content);
        $this->assertStringContainsString('Td (Wins) Tj ET', $content);
        $this->assertStringContainsString('Td (Deduction) Tj ET', $content);
        $this->assertStringContainsString('Alex Santos', $content);
        $this->assertTrue(strpos($content, '(Alex Santos)') < strpos($content, '(Aaron Low)'));
        $this->assertStringContainsString('Td (3.00) Tj ET', $content);
        $this->assertStringContainsString('Td (1.00) Tj ET', $content);
        $this->assertStringContainsString('Td (2.00) Tj ET', $content);
        $this->assertStringContainsString('Td (50.00) Tj ET', $content);
    }

    public function test_criteria_pdf_displays_zero_scores_for_no_entry_on_one_landscape_page(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['name' => 'Arts Festival']);
        $category = $event->categories()->create(['name' => 'Visual Arts']);
        $competition = $category->competitions()->create(['name' => 'Drawing']);

        for ($number = 1; $number <= 7; $number++) {
            $competition->criteria()->create(['name' => "Criterion {$number}", 'max_score' => 10]);
        }

        $participant = $event->participants()->create(['name' => 'No Entry Participant']);
        $competition->results()->create(['participant_id' => $participant->id, 'has_entry' => false]);

        $response = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $content = $response->getContent();
        $this->assertStringContainsString('/MediaBox [0 0 842 595]', $content);
        $this->assertStringContainsString('/Count 1', $content);
        $this->assertStringContainsString('Criteria columns 1-6 of 7', $content);
        $this->assertStringContainsString('Criteria columns 7-7 of 7', $content);
        $this->assertStringContainsString('Td (Averaged scores) Tj ET', $content);
        $this->assertStringContainsString('Td (Judge 1 scores) Tj ET', $content);
        $this->assertStringContainsString('Td (No entry) Tj ET', $content);
        $this->assertStringContainsString('Td (Criterion 7) Tj ET', $content);
        $this->assertGreaterThanOrEqual(7, substr_count($content, 'Td (0.00) Tj ET'));
    }

    public function test_landscape_pdf_repeats_table_headers_when_participants_fill_multiple_pages(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['name' => 'Large Event']);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Relay',
            'scoring_method' => CompetitionScoringMethod::Wins,
        ]);

        for ($number = 1; $number <= 30; $number++) {
            $event->participants()->create(['name' => sprintf('Participant %02d', $number)]);
        }

        $response = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]));

        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('/Count 2', $content);
        $this->assertSame(2, substr_count($content, 'Td (Participant) Tj ET'));
        $this->assertStringContainsString('Page 2 of 2', $content);
        $this->assertTrue(strpos($content, '(Participant 01)') < strpos($content, '(Participant 30)'));
    }

    public function test_multi_judge_exports_show_averaged_scores_instead_of_summed_judge_scores(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['name' => 'Arts Festival']);
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting', 'judge_count' => 2]);
        $criterion = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $visualAppeal = $competition->criteria()->create(['name' => 'Visual appeal', 'max_score' => 100]);
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $result = $competition->results()->create(['participant_id' => $participant->id]);
        $result->criterionScores()->createMany([
            ['criterion_id' => $criterion->id, 'judge_number' => 1, 'score' => 80],
            ['criterion_id' => $criterion->id, 'judge_number' => 2, 'score' => 100],
            ['criterion_id' => $visualAppeal->id, 'judge_number' => 1, 'score' => 20],
            ['criterion_id' => $visualAppeal->id, 'judge_number' => 2, 'score' => 40],
        ]);

        $excel = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'xlsx',
        ]));
        $pdf = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]));

        $excel->assertOk();
        $pdf->assertOk();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'test-judge-scores-');
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

        $this->assertStringContainsString('Technique average', $sheet);
        $this->assertStringContainsString('<c r="B8"><v>2</v></c>', $sheet);
        $this->assertStringContainsString('<c r="A9" t="inlineStr"><is><t xml:space="preserve">Averaged scores</t>', $sheet);
        $this->assertStringContainsString('<c r="D11"><v>90</v></c><c r="E11"><v>30</v></c><c r="F11"><v>120</v></c>', $sheet);
        $this->assertStringContainsString('<c r="A13" t="inlineStr"><is><t xml:space="preserve">Judge 1 scores</t>', $sheet);
        $this->assertStringContainsString('<c r="D15"><v>80</v></c><c r="E15"><v>20</v></c><c r="F15"><v>100</v></c>', $sheet);
        $this->assertStringContainsString('<c r="A17" t="inlineStr"><is><t xml:space="preserve">Judge 2 scores</t>', $sheet);
        $this->assertStringContainsString('<c r="D19"><v>100</v></c><c r="E19"><v>40</v></c><c r="F19"><v>140</v></c>', $sheet);
        $this->assertStringContainsString('Judges: 2', $pdf->getContent());
        $this->assertStringContainsString('/Count 1', $pdf->getContent());
        $this->assertStringContainsString('Td (Averaged scores) Tj ET', $pdf->getContent());
        $this->assertStringContainsString('Td (Judge 1 scores) Tj ET', $pdf->getContent());
        $this->assertStringContainsString('Td (Judge 2 scores) Tj ET', $pdf->getContent());
        $this->assertTrue(strpos($pdf->getContent(), 'Td (Averaged scores) Tj ET') < strpos($pdf->getContent(), 'Td (Judge 1 scores) Tj ET'));
        $this->assertTrue(strpos($pdf->getContent(), 'Td (Judge 1 scores) Tj ET') < strpos($pdf->getContent(), 'Td (Judge 2 scores) Tj ET'));
        $this->assertStringContainsString('Td (80.00) Tj ET', $pdf->getContent());
        $this->assertStringContainsString('Td (100.00) Tj ET', $pdf->getContent());
        $this->assertStringContainsString('Td (140.00) Tj ET', $pdf->getContent());
        $this->assertStringContainsString('Td (90.00) Tj ET', $pdf->getContent());
        $this->assertStringContainsString('Td (120.00) Tj ET', $pdf->getContent());
        $this->assertStringNotContainsString('Td (180.00) Tj ET', $pdf->getContent());
    }

    public function test_judge_tables_keep_pending_scores_blank_and_no_entry_scores_zero(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['name' => 'Arts Festival']);
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting', 'judge_count' => 2]);
        $criterion = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $scored = $event->participants()->create(['name' => 'Alex Scored']);
        $pending = $event->participants()->create(['name' => 'Bob Pending']);
        $noEntry = $event->participants()->create(['name' => 'Carla No Entry']);
        $scoredResult = $competition->results()->create(['participant_id' => $scored->id]);
        $scoredResult->criterionScores()->createMany([
            ['criterion_id' => $criterion->id, 'judge_number' => 1, 'score' => 80],
            ['criterion_id' => $criterion->id, 'judge_number' => 2, 'score' => 100],
        ]);
        $pendingResult = $competition->results()->create(['participant_id' => $pending->id]);
        $pendingResult->criterionScores()->create([
            'criterion_id' => $criterion->id,
            'judge_number' => 1,
            'score' => 60,
        ]);
        $competition->results()->create(['participant_id' => $noEntry->id, 'has_entry' => false]);

        $response = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'xlsx',
        ]));

        $response->assertOk();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'test-judge-status-');
        $this->assertNotFalse($temporaryPath);

        try {
            file_put_contents($temporaryPath, $response->getContent());
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($temporaryPath));
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $archive->close();
            $this->assertIsString($sheet);
        } finally {
            unlink($temporaryPath);
        }

        $this->assertStringContainsString('<c r="D11"><v>90</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D12"/><c r="E12"/>', $sheet);
        $this->assertStringContainsString('<c r="D13"><v>0</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D18"><v>60</v></c><c r="E18"><v>60</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D19"><v>0</v></c><c r="E19"><v>0</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D24"/><c r="E24"/>', $sheet);
        $this->assertStringContainsString('<c r="D25"><v>0</v></c><c r="E25"><v>0</v></c>', $sheet);
    }

    public function test_pdf_keeps_each_table_together_when_it_fits_on_a_fresh_page(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['name' => 'Arts Festival']);
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting', 'judge_count' => 2]);
        $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);

        for ($number = 1; $number <= 12; $number++) {
            $event->participants()->create(['name' => sprintf('Participant %02d', $number)]);
        }

        $response = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]));

        $response->assertOk();
        $content = $response->getContent();
        preg_match_all('/\nstream\n(.*?)endstream/s', $content, $matches);

        $this->assertStringContainsString('/Count 3', $content);
        $this->assertCount(3, $matches[1]);
        $this->assertStringContainsString('Td (Averaged scores) Tj ET', $matches[1][0]);
        $this->assertStringNotContainsString('Td (Judge 1 scores) Tj ET', $matches[1][0]);
        $this->assertStringContainsString('Td (Judge 1 scores) Tj ET', $matches[1][1]);
        $this->assertStringNotContainsString('Td (Judge 2 scores) Tj ET', $matches[1][1]);
        $this->assertStringContainsString('Td (Judge 2 scores) Tj ET', $matches[1][2]);
        $this->assertSame(1, substr_count($matches[1][0], 'Td (Participant 12) Tj ET'));
        $this->assertSame(1, substr_count($matches[1][1], 'Td (Participant 12) Tj ET'));
        $this->assertSame(1, substr_count($matches[1][2], 'Td (Participant 12) Tj ET'));
    }

    public function test_pdf_starts_the_next_table_after_a_long_table_continues(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['name' => 'Arts Festival']);
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting']);
        $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);

        for ($number = 1; $number <= 20; $number++) {
            $event->participants()->create(['name' => sprintf('Participant %02d', $number)]);
        }

        $response = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]));

        $response->assertOk();
        $content = $response->getContent();
        preg_match_all('/\nstream\n(.*?)endstream/s', $content, $matches);

        $this->assertStringContainsString('/Count 3', $content);
        $this->assertCount(3, $matches[1]);
        $this->assertStringContainsString('Td (Averaged scores) Tj ET', $matches[1][0]);
        $this->assertStringContainsString('Td (Averaged scores) Tj ET', $matches[1][1]);
        $this->assertStringContainsString('Td (Participant 20) Tj ET', $matches[1][1]);
        $this->assertStringContainsString('Td (Judge 1 scores) Tj ET', $matches[1][1]);
        $this->assertStringContainsString('Td (Judge 1 scores) Tj ET', $matches[1][2]);
    }

    public function test_score_exports_require_access_and_are_scoped_to_the_event(): void
    {
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting']);
        $otherEvent = Event::factory()->create();
        $otherCategory = $otherEvent->categories()->create(['name' => 'Other']);
        $route = route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]);

        $this->get($route)->assertRedirect(route('login'));

        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $this->actingAs($auditor)
            ->get(route('events.categories.competitions.scores.export', [
                $otherEvent, $category, $competition, 'format' => 'pdf',
            ]))->assertNotFound();
        $this->actingAs($auditor)
            ->get(route('events.categories.competitions.scores.export', [
                $event, $otherCategory, $competition, 'format' => 'xlsx',
            ]))->assertNotFound();
        $this->actingAs($auditor)
            ->get(str_replace('/pdf', '/csv', $route))->assertNotFound();

        $this->actingAs($auditor)->get($route)->assertOk();
    }
}
