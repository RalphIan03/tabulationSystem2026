<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_archive_completed_or_inactive_events_but_not_an_active_ongoing_event(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $completed = Event::factory()->create([
            'name' => 'Completed Games',
            'start_date' => today()->subDays(4),
            'end_date' => today()->subDay(),
        ]);
        $inactive = Event::factory()->create([
            'name' => 'Inactive Games',
            'is_active' => false,
            'start_date' => today(),
            'end_date' => today()->addDay(),
        ]);
        $ongoing = Event::factory()->create([
            'name' => 'Ongoing Games',
            'start_date' => today(),
            'end_date' => today()->addDay(),
        ]);

        $this->actingAs($admin)->patch(route('events.archive', $ongoing))
            ->assertSessionHasErrors('archive');
        $this->assertNull($ongoing->refresh()->archived_at);

        foreach ([$completed, $inactive] as $event) {
            $this->actingAs($admin)->patch(route('events.archive', $event))
                ->assertRedirect(route('events.show', $event));

            $this->assertTrue($event->refresh()->isArchived());
            $this->assertFalse($event->is_active);
            $this->assertTrue($event->leaderboard_frozen);
        }

        $this->actingAs($admin)->get(route('events.index'))
            ->assertSee('Ongoing Games')
            ->assertDontSee('Completed Games')
            ->assertDontSee('Inactive Games');
        $this->actingAs($admin)->get(route('events.index', ['archived' => 1]))
            ->assertSee('Completed Games')
            ->assertSee('Inactive Games')
            ->assertDontSee('Ongoing Games')
            ->assertDontSee('Add event');
    }

    public function test_archived_event_only_offers_result_and_report_downloads_and_rejects_mutations(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create([
            'name' => 'Archived Sports',
            'start_date' => today()->subDays(3),
            'end_date' => today()->subDay(),
        ]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $competition->rankScores()->create(['rank' => 1, 'points' => 100]);
        $participant = $event->participants()->create(['name' => 'Alex Santos']);
        $lowerParticipant = $event->participants()->create(['name' => '=SUM(1,1)']);
        $absentParticipant = $event->participants()->create(['name' => 'No Entry']);
        $competition->results()->create(['participant_id' => $participant->id, 'wins' => 3]);
        $competition->results()->create(['participant_id' => $lowerParticipant->id, 'wins' => 1]);
        $competition->results()->create(['participant_id' => $absentParticipant->id, 'has_entry' => false]);

        $this->actingAs($admin)->patch(route('events.archive', $event))->assertRedirect();

        $this->actingAs($admin)->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Download event results (CSV)')
            ->assertSee('Download Excel')
            ->assertSee('Download PDF')
            ->assertDontSee('Edit event details')
            ->assertDontSee('Enter scores')
            ->assertDontSee('Delete event');

        $this->actingAs($admin)->patch(route('events.toggle-status', $event))->assertForbidden();
        $this->actingAs($admin)->patch(route('events.details.update', $event), [
            'event_name' => 'Changed',
            'start_date' => today()->subDays(3)->toDateString(),
            'end_date' => today()->subDay()->toDateString(),
        ])->assertForbidden();
        $this->actingAs($admin)->delete(route('events.destroy', $event))->assertForbidden();
        $this->actingAs($admin)->post(route('events.participants.store', $event), [
            'participant_name' => 'New participant',
        ])->assertForbidden();
        $this->actingAs($admin)->patch(route('events.categories.competitions.scores.finalization.update', [
            $event, $category, $competition,
        ]))->assertForbidden();
        $this->actingAs($admin)->patch(route('events.competition-scores.exclude-linked', $event), [
            'competition_ids' => [$competition->id],
        ])->assertForbidden();
        $this->actingAs($admin)->get(route('events.leaderboard', $event))->assertForbidden();

        $this->assertSame('Archived Sports', $event->refresh()->name);
        $this->assertSame(3, $event->participants()->count());
        $this->assertTrue($event->isArchived());

        $csv = $this->actingAs($admin)->get(route('events.results.download', $event));
        $csv->assertOk()->assertDownload("archived-sports-{$event->id}-results.csv");
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Overall results', $content);
        $this->assertStringContainsString('Alex Santos', $content);
        $this->assertStringContainsString("'=SUM(1,1)", $content);
        $this->assertStringContainsString('Basketball', $content);
        $this->assertStringNotContainsString('No Entry', $content);
        $this->assertTrue(strpos($content, 'Alex Santos') < strpos($content, "'=SUM(1,1)"));

        $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'xlsx',
        ]))->assertOk()->assertDownload();
        $this->actingAs($admin)->get(route('events.categories.competitions.scores.export', [
            $event, $category, $competition, 'format' => 'pdf',
        ]))->assertOk()->assertDownload();
    }

    public function test_auditors_and_public_cannot_access_archived_events(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $archived = Event::factory()->create([
            'name' => 'Old Games',
            'start_date' => today()->subDays(3),
            'end_date' => today()->subDay(),
        ]);
        $category = $archived->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Race']);
        $current = Event::factory()->create([
            'name' => 'Current Games',
            'start_date' => today(),
            'end_date' => today()->addDay(),
        ]);
        $this->actingAs($admin)->patch(route('events.archive', $archived))->assertRedirect();

        $this->actingAs($auditor)->get(route('events.index'))
            ->assertSee('Current Games')->assertDontSee('Old Games');
        $this->actingAs($auditor)->get(route('events.show', $archived))->assertNotFound();
        $this->actingAs($auditor)->get(route('events.results.download', $archived))->assertNotFound();
        $this->actingAs($auditor)->get(route('events.categories.competitions.scores.export', [
            $archived, $category, $competition, 'format' => 'pdf',
        ]))->assertNotFound();
        $this->actingAs($auditor)->patch(route('events.archive', $current))->assertForbidden();

        $this->get(route('leaderboards.index'))->assertSee('Current Games')->assertDontSee('Old Games');
        $this->get(route('leaderboards.show', $archived))->assertNotFound();
        $this->get(route('leaderboards.data', $archived))->assertNotFound();
    }
}
