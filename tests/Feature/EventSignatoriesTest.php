<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

final class EventSignatoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_named_signatories_and_disable_team_signatures(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();

        $this->actingAs($admin)->patch(route('events.signatories.update', $event), [
            'report_signatories' => "Dr. Ana Reyes\nBen Cruz — Auditor",
            'include_team_signatories' => '0',
            'name' => 'Unexpected name change',
        ])->assertRedirect(route('events.show', $event))
            ->assertSessionHas('status', 'Report signatories updated successfully.');

        $event->refresh();
        $this->assertSame("Dr. Ana Reyes\nBen Cruz — Auditor", $event->report_signatories);
        $this->assertFalse($event->include_team_signatories);
        $this->assertNotSame('Unexpected name change', $event->name);
        $this->get(route('events.show', $event))
            ->assertSee('action="'.route('events.signatories.update', $event, false).'"', false)
            ->assertSee('Save report signatories')
            ->assertSee("Dr. Ana Reyes\nBen Cruz — Auditor")
            ->assertSee('data-loading-text="Saving report signatories…"', false);

        $log = ActivityLog::query()->where('action', 'events.signatories.update')->sole();
        $this->assertContains([
            'operation' => 'updated',
            'type' => 'Event',
            'id' => (string) $event->id,
            'label' => $event->name,
        ], $log->affectedRecords());
    }

    public function test_admin_can_clear_named_signatories_and_enable_team_signatures(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create([
            'report_signatories' => 'Previous signer',
            'include_team_signatories' => false,
        ]);

        $this->actingAs($admin)->patch(route('events.signatories.update', $event), [
            'report_signatories' => '',
            'include_team_signatories' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertNull($event->report_signatories);
        $this->assertTrue($event->include_team_signatories);
    }

    public function test_auditors_cannot_view_or_change_signatory_configuration(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create(['report_signatories' => 'Original signer']);

        $this->actingAs($auditor)->get(route('events.show', $event))
            ->assertDontSee('Save report signatories');
        $this->patch(route('events.signatories.update', $event), [
            'report_signatories' => 'Changed signer',
            'include_team_signatories' => false,
        ])->assertForbidden();

        $event->refresh();
        $this->assertSame('Original signer', $event->report_signatories);
        $this->assertTrue($event->include_team_signatories);
    }

    public function test_guests_cannot_change_signatory_configuration(): void
    {
        $event = Event::factory()->create();

        $this->patch(route('events.signatories.update', $event), [
            'report_signatories' => 'Changed signer',
            'include_team_signatories' => false,
        ])->assertRedirect(route('login'));

        $this->assertNull($event->refresh()->report_signatories);
    }

    public function test_archived_event_signatories_are_read_only(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['archived_at' => now(), 'report_signatories' => 'Original signer']);

        $this->actingAs($admin)->patch(route('events.signatories.update', $event), [
            'report_signatories' => 'Changed signer',
            'include_team_signatories' => false,
        ])->assertForbidden();

        $this->assertSame('Original signer', $event->refresh()->report_signatories);
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_signatory_settings_do_not_change_the_event(array $payload, string $field): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['report_signatories' => 'Original signer']);

        $this->actingAs($admin)->patch(route('events.signatories.update', $event), $payload)
            ->assertSessionHasErrors($field);

        $event->refresh();
        $this->assertSame('Original signer', $event->report_signatories);
        $this->assertTrue($event->include_team_signatories);
    }

    public function test_named_signers_remain_in_pdf_and_excel_when_team_signatures_are_disabled(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create([
            'report_signatories' => "Dr. Ana Reyes\r\n\r\n  Ben Cruz (Chair)  \n=Approved & Checked",
            'include_team_signatories' => false,
        ]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Relay',
            'scoring_method' => CompetitionScoringMethod::Ranking,
        ]);
        $team = $event->participants()->create(['name' => 'TEAM A']);
        $linkedTeam = $event->participants()->create(['name' => 'TEAM A2', 'parent_participant_id' => $team->id]);
        $absent = $event->participants()->create(['name' => 'TEAM B']);
        $competition->results()->createMany([
            ['participant_id' => $linkedTeam->id, 'placement' => 1],
            ['participant_id' => $absent->id, 'has_entry' => false],
        ]);
        $parameters = [$event, $category, $competition];

        $pdfResponse = $this->actingAs($auditor)->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'pdf']))
            ->assertDownload()->assertHeader('Content-Type', 'application/pdf');
        $excelResponse = $this->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'xlsx']))
            ->assertDownload()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $pdf = $pdfResponse->getContent();
        $sheet = $this->worksheet($excelResponse->getContent());
        $this->assertStringContainsString('Td (Signatories) Tj ET', $pdf);
        $this->assertStringContainsString('Td (Dr. Ana Reyes) Tj ET', $pdf);
        $this->assertStringContainsString('Td (Ben Cruz \\(Chair\\)) Tj ET', $pdf);
        $this->assertStringContainsString('Td (=Approved & Checked) Tj ET', $pdf);
        $this->assertStringContainsString('Signatories', $sheet);
        $this->assertStringContainsString('Dr. Ana Reyes', $sheet);
        $this->assertStringContainsString('Ben Cruz (Chair)', $sheet);
        $this->assertStringContainsString('=Approved &amp; Checked', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);

        $this->assertStringNotContainsString('Team signatures', $pdf);
        $this->assertStringNotContainsString('Team signatures', $sheet);
        $this->assertStringNotContainsString('Authorized representative / signature', $pdf);
        $this->assertStringNotContainsString('Authorized representative / signature', $sheet);
    }

    public function test_team_signatures_include_each_participating_main_team_in_both_formats_by_default(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['report_signatories' => 'Event chair']);
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting']);
        $criterion = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $team = $event->participants()->create(['name' => 'TEAM A']);
        $linkedTeam = $event->participants()->create(['name' => 'TEAM A2', 'parent_participant_id' => $team->id]);
        $absent = $event->participants()->create(['name' => 'TEAM B']);
        $result = $competition->results()->create(['participant_id' => $linkedTeam->id]);
        $result->criterionScores()->create(['criterion_id' => $criterion->id, 'score' => 90]);
        $competition->results()->create(['participant_id' => $absent->id, 'has_entry' => false]);
        $parameters = [$event, $category, $competition];

        $pdfResponse = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'pdf']))
            ->assertDownload();
        $excelResponse = $this->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'xlsx']))
            ->assertDownload();

        $pdf = $pdfResponse->getContent();
        $sheet = $this->worksheet($excelResponse->getContent());
        $this->assertStringContainsString('Td (Event chair) Tj ET', $pdf);
        $this->assertStringContainsString('Event chair', $sheet);
        $this->assertStringContainsString('Td (Team signatures) Tj ET', $pdf);
        $this->assertStringContainsString('Team signatures', $sheet);
        $pdfSignatures = substr($pdf, strpos($pdf, 'Td (Team signatures) Tj ET'));
        $excelSignatures = substr($sheet, strpos($sheet, 'Team signatures'));
        $this->assertStringContainsString('Td (TEAM A) Tj ET', $pdfSignatures);
        $this->assertStringNotContainsString('Td (TEAM A2) Tj ET', $pdfSignatures);
        $this->assertStringNotContainsString('Td (TEAM B) Tj ET', $pdfSignatures);
        $this->assertStringContainsString('>TEAM A<', $excelSignatures);
        $this->assertStringNotContainsString('>TEAM A2<', $excelSignatures);
        $this->assertStringNotContainsString('>TEAM B<', $excelSignatures);
    }

    public function test_reports_omit_signature_sections_when_no_signatories_are_selected(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create([
            'report_signatories' => " \r\n \n ",
            'include_team_signatories' => false,
        ]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Relay', 'scoring_method' => CompetitionScoringMethod::Wins]);
        $parameters = [$event, $category, $competition];

        $pdf = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'pdf']))
            ->assertDownload()->getContent();
        $excel = $this->get(route('events.categories.competitions.scores.export', [...$parameters, 'format' => 'xlsx']))
            ->assertDownload()->getContent();

        $sheet = $this->worksheet($excel);
        $this->assertStringNotContainsString('Signatories', $pdf);
        $this->assertStringNotContainsString('Team signatures', $pdf);
        $this->assertStringNotContainsString('Signatories', $sheet);
        $this->assertStringNotContainsString('Team signatures', $sheet);
    }

    public function test_many_named_signatories_continue_across_pdf_pages(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $names = array_map(static fn (int $index): string => sprintf('Report signer %02d', $index), range(1, 20));
        $event = Event::factory()->create([
            'report_signatories' => implode("\n", $names),
            'include_team_signatories' => false,
        ]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Relay', 'scoring_method' => CompetitionScoringMethod::Wins]);

        $response = $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]))->assertDownload();

        $pdf = $response->getContent();
        $this->assertStringContainsString('Signatories \\(continued\\)', $pdf);
        foreach ($names as $name) {
            $this->assertStringContainsString('Td ('.$name.') Tj ET', $pdf);
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidSettings(): array
    {
        return [
            'signers must be text' => [['report_signatories' => ['Unexpected array'], 'include_team_signatories' => true], 'report_signatories'],
            'signers length limit' => [['report_signatories' => str_repeat('a', 2001), 'include_team_signatories' => true], 'report_signatories'],
            'toggle must be boolean' => [['report_signatories' => 'Changed signer', 'include_team_signatories' => 'unexpected'], 'include_team_signatories'],
            'toggle is required' => [['report_signatories' => 'Changed signer'], 'include_team_signatories'],
        ];
    }

    private function worksheet(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'signatory-test-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $content);
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path));
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $archive->close();
            $this->assertIsString($sheet);

            return $sheet;
        } finally {
            unlink($path);
        }
    }
}
