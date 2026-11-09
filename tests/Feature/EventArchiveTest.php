<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
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

    public function test_archived_event_offers_downloads_and_restore_while_rejecting_other_mutations(): void
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
            ->assertSee('Restore event')
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

    public function test_admin_can_restore_an_event_without_changing_its_results_or_configuration(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create([
            'name' => 'Restorable Games',
            'is_active' => false,
            'archived_at' => now(),
            'leaderboard_frozen' => true,
            'report_signatories' => 'Event Head',
            'include_team_signatories' => false,
        ]);
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create([
            'name' => 'Basketball',
            'scoring_method' => CompetitionScoringMethod::Wins,
            'scores_finalized_at' => now(),
        ]);
        $rank = $competition->rankScores()->create(['rank' => 1, 'points' => 100]);
        $participant = $event->participants()->create(['name' => 'TEAM A', 'overall_deduction' => 7]);
        $linked = $event->participants()->create(['name' => 'TEAM A2', 'parent_participant_id' => $participant->id]);
        $result = $competition->results()->create(['participant_id' => $linked->id, 'wins' => 3, 'deduction' => 5]);
        $eventAttributes = $event->refresh()->getAttributes();
        $categoryAttributes = $category->refresh()->getAttributes();
        $competitionAttributes = $competition->refresh()->getAttributes();
        $resultAttributes = $result->refresh()->getAttributes();

        $this->actingAs($admin)->patch(route('events.restore', $event), [
            'name' => 'Unexpected rename',
            'is_active' => true,
            'leaderboard_frozen' => false,
        ])->assertRedirect(route('events.show', $event))
            ->assertSessionHas('status', 'Event restored successfully. It remains inactive until activated.');

        $eventAttributes['archived_at'] = null;
        unset($eventAttributes['updated_at']);
        $this->assertSame($eventAttributes, array_diff_key($event->refresh()->getAttributes(), ['updated_at' => true]));
        $this->assertSame($categoryAttributes, $category->refresh()->getAttributes());
        $this->assertSame($competitionAttributes, $competition->refresh()->getAttributes());
        $this->assertSame($resultAttributes, $result->refresh()->getAttributes());
        $this->assertSame('7.00', $participant->refresh()->overall_deduction);
        $this->assertSame($participant->id, $linked->refresh()->parent_participant_id);
        $this->assertSame('100.00', $rank->refresh()->points);
        $this->assertSame(2, $event->participants()->count());
        $this->assertSame(1, $event->competitions()->count());
        $this->get(route('events.show', $event))->assertSee('Edit event details')->assertSee('Activate event')->assertDontSee('Restore event');
        $this->get(route('events.index'))->assertSee('Restorable Games');
        $this->get(route('events.index', ['archived' => 1]))->assertDontSee('Restorable Games');
        $this->patch(route('events.details.update', $event), [
            'event_name' => 'Restored Games',
            'start_date' => $event->start_date->toDateString(),
            'end_date' => $event->end_date->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Restored Games', $event->refresh()->name);

        $log = ActivityLog::query()->where('route_name', 'events.restore')->firstOrFail();
        $this->assertSame('Restored an archived event', $log->description);
        $this->assertSame('update', $log->interaction_type);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame([
            ['operation' => 'updated', 'type' => 'Event', 'id' => (string) $event->id, 'label' => 'Restorable Games'],
        ], $log->context['records']);
    }

    public function test_restore_refreshes_dashboard_caches_and_requires_activation_before_auditor_access(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create([
            'name' => 'Restored Dashboard Games',
            'is_active' => false,
            'archived_at' => now(),
            'leaderboard_frozen' => true,
            'start_date' => today()->subDay(),
            'end_date' => today()->addDay(),
        ]);
        $this->actingAs($admin)->get(route('dashboard'))->assertViewHas('total_events', 0);
        $this->actingAs($auditor)->get(route('dashboard'))->assertViewHas('event_count', 0);

        $this->actingAs($admin)->patch(route('events.restore', $event))->assertRedirect();

        $this->get(route('dashboard'))->assertViewHas('total_events', 1)->assertSee('Restored Dashboard Games');
        $this->actingAs($auditor)->get(route('events.show', $event))->assertNotFound();
        $this->get(route('events.index'))->assertDontSee('Restored Dashboard Games');
        $this->get(route('dashboard'))->assertViewHas('event_count', 0);
        $this->actingAs($admin)->patch(route('events.toggle-status', $event))->assertRedirect();
        $this->actingAs($auditor)->get(route('events.show', $event))->assertOk()->assertDontSee('Restore event');
        $this->get(route('dashboard'))->assertViewHas('event_count', 1)->assertSee('Restored Dashboard Games');
        $this->assertTrue($event->refresh()->is_active);
        $this->assertFalse($event->isArchived());
    }

    public function test_restore_buttons_use_relative_form_actions_with_loading_and_confirmation(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['archived_at' => now(), 'is_active' => false]);
        URL::forceScheme('http');
        $action = route('events.restore', $event, false);

        foreach ([route('events.index', ['archived' => 1], false), route('events.show', $event, false)] as $pagePath) {
            $response = $this->actingAs($admin)->get('https://tabulation.example'.$pagePath)->assertSee('Restore event');
            $document = new DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new DOMXPath($document);
            $forms = $xpath->query('//form[@action="'.$action.'"]');
            $this->assertSame(1, $forms->length);
            $form = $forms->item(0);
            $this->assertSame('POST', $form->getAttribute('method'));
            $this->assertSame('Restoring event…', $form->getAttribute('data-loading-text'));
            $this->assertStringContainsString('confirm(', $form->getAttribute('onsubmit'));
            $this->assertSame(1, $xpath->query('input[@name="_method" and @value="PATCH"]', $form)->length);
            $this->assertSame(1, $xpath->query('input[@name="_token"]', $form)->length);
        }
    }

    public function test_guests_and_auditors_cannot_restore_archived_events(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create(['archived_at' => now(), 'is_active' => false]);
        $activeEvent = Event::factory()->create();
        $archivedAt = $event->archived_at;

        $this->patch(route('events.restore', $event))->assertRedirect(route('login'));
        $this->actingAs($auditor)->patch(route('events.restore', $event))->assertNotFound();
        $this->patch(route('events.restore', $activeEvent))->assertForbidden();

        $this->assertSame($archivedAt->toISOString(), $event->refresh()->archived_at->toISOString());
        $this->assertFalse($event->is_active);
        $this->assertNull($activeEvent->refresh()->archived_at);
        $this->assertTrue($activeEvent->is_active);
    }

    public function test_non_archived_events_cannot_be_restored_or_deactivated_by_a_restore_request(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['leaderboard_frozen' => false]);

        $this->actingAs($admin)->patch(route('events.restore', $event))
            ->assertSessionHasErrors(['restore' => 'Only archived events can be restored.']);

        $this->assertNull($event->refresh()->archived_at);
        $this->assertTrue($event->is_active);
        $this->assertFalse($event->leaderboard_frozen);
    }
}
