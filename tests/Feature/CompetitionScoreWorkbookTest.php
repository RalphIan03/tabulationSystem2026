<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Event;
use App\Models\User;
use App\Services\LeaderboardCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

final class CompetitionScoreWorkbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_scored_workbook_imports_and_criterion_ranks_appear_in_reports(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting', 'judge_count' => 2]);
        $technique = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $competition->criteria()->create(['name' => 'Creativity', 'max_score' => 100]);
        $event->participants()->create(['name' => 'Alice']);
        $event->participants()->create(['name' => 'Bob']);
        $parameters = [$event, $category, $competition];
        $template = $this->actingAs($auditor)
            ->get(route('events.categories.competitions.scores.template', $parameters))
            ->assertOk()->assertDownload("scoring-template-{$competition->id}.xlsx")->getContent();
        $scored = $this->withScores($template, [
            'E3' => 90, 'F3' => 50, 'E4' => 80, 'F4' => 50,
            'E5' => 80, 'F5' => 70, 'E6' => 80, 'F6' => 70,
        ]);

        $this->post(route('events.categories.competitions.scores.import', $parameters), [
            'score_file' => UploadedFile::fake()->createWithContent('scores.xlsx', $scored),
        ])->assertRedirect(route('events.categories.competitions.scores.edit', $parameters));

        $this->assertDatabaseHas('criterion_scores', ['criterion_id' => $technique->id, 'judge_number' => 1, 'score' => 90]);
        $criterionRanks = app(LeaderboardCalculator::class)->criterionStandings($competition->fresh(), $event);
        $this->assertSame('Creativity', $criterionRanks[0]['criterion']->name);
        $this->assertSame('Bob', $criterionRanks[0]['standings'][0]['participant']->name);
        $this->assertSame(70.0, $criterionRanks[0]['standings'][0]['score']);
        $this->assertSame('Technique', $criterionRanks[1]['criterion']->name);
        $this->assertSame('Alice', $criterionRanks[1]['standings'][0]['participant']->name);
        $this->assertSame(85.0, $criterionRanks[1]['standings'][0]['score']);
        $this->get(route('events.categories.competitions.scores.edit', $parameters))
            ->assertSee('Best on each criterion')->assertSee('Pending finalization');
        $this->get(route('events.show', $event))->assertSee('Pending finalization');
        $excel = $this->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'xlsx']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Best on Technique', $this->worksheet($excel));
        $pdf = $this->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'pdf']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Best on Creativity', $pdf);
    }

    public function test_invalid_import_is_atomic_and_auditor_cannot_import_finalized_scores(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting']);
        $competition->criteria()->create(['name' => 'Technique', 'max_score' => 50]);
        $event->participants()->create(['name' => 'Alice']);
        $parameters = [$event, $category, $competition];
        $template = $this->actingAs($auditor)
            ->get(route('events.categories.competitions.scores.template', $parameters))->getContent();

        $this->post(route('events.categories.competitions.scores.import', $parameters), [
            'score_file' => UploadedFile::fake()->createWithContent('scores.xlsx', $this->withScores($template, ['E3' => 51])),
        ])->assertSessionHasErrors('score_file');
        $this->assertDatabaseCount('criterion_scores', 0);

        $otherContest = $category->competitions()->create(['name' => 'Sculpture']);
        $otherContest->criteria()->create(['name' => 'Technique', 'max_score' => 50]);
        $this->post(route('events.categories.competitions.scores.import', [$event, $category, $otherContest]), [
            'score_file' => UploadedFile::fake()->createWithContent('scores.xlsx', $template),
        ])->assertSessionHasErrors('score_file');
        $this->assertDatabaseCount('criterion_scores', 0);

        $competition->update(['scores_finalized_at' => now()]);
        $this->post(route('events.categories.competitions.scores.import', $parameters), [
            'score_file' => UploadedFile::fake()->createWithContent('scores.xlsx', $template),
        ])->assertForbidden();
        $this->assertDatabaseCount('criterion_scores', 0);
    }

    /** @param array<string, int> $values */
    private function withScores(string $content, array $values): string
    {
        $path = tempnam(sys_get_temp_dir(), 'score-test-');
        file_put_contents($path, $content);

        try {
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path));
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');

            foreach ($values as $cell => $value) {
                $sheet = str_replace("<c r=\"{$cell}\"/>", "<c r=\"{$cell}\"><v>{$value}</v></c>", $sheet);
            }

            $archive->addFromString('xl/worksheets/sheet1.xml', $sheet);
            $archive->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function worksheet(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'score-test-');
        file_put_contents($path, $content);

        try {
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path));
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $archive->close();

            return $sheet;
        } finally {
            unlink($path);
        }
    }
}
