<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_deactivate_and_reactivate_an_event_without_deleting_its_data(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['name' => 'Regional Games']);
        $event->participants()->create(['name' => 'Alex']);

        $this->actingAs($admin)
            ->patch(route('events.toggle-status', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHas('status', 'Event deactivated successfully.');

        $this->assertFalse($event->refresh()->is_active);
        $this->assertSame(1, $event->participants()->count());

        $this->actingAs($admin)->get(route('events.index'))
            ->assertSee('Regional Games')
            ->assertSee('Inactive')
            ->assertSee('Activate');
        $this->actingAs($admin)->get(route('events.show', $event))
            ->assertSee('Inactive · hidden from auditors');

        $this->actingAs($admin)
            ->patch(route('events.toggle-status', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHas('status', 'Event activated successfully.');

        $this->assertTrue($event->refresh()->is_active);
    }

    public function test_inactive_events_are_hidden_and_inaccessible_to_auditors(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $active = Event::factory()->create(['name' => 'Visible Games']);
        $inactive = Event::factory()->create([
            'name' => 'Hidden Games',
            'is_active' => false,
            'start_date' => today()->subDay(),
            'end_date' => today()->addDay(),
        ]);
        $category = $inactive->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Race']);

        $this->actingAs($auditor)->get(route('events.index'))
            ->assertSee($active->name)
            ->assertDontSee($inactive->name);
        $this->actingAs($auditor)->get(route('dashboard'))
            ->assertDontSee($inactive->name);
        $this->actingAs($auditor)->get(route('events.show', $inactive))->assertNotFound();
        $this->actingAs($auditor)->get(route('events.leaderboard', $inactive))->assertNotFound();
        $this->actingAs($auditor)
            ->get(route('events.categories.competitions.show', [$inactive, $category, $competition]))
            ->assertNotFound();
        $this->actingAs($auditor)
            ->post(route('events.participants.store', $inactive), ['participant_name' => 'No access'])
            ->assertNotFound();

        $this->assertSame(0, $inactive->participants()->count());
    }

    public function test_auditor_cannot_change_event_activation(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create();

        $this->actingAs($auditor)
            ->patch(route('events.toggle-status', $event))
            ->assertForbidden();

        $this->assertTrue($event->refresh()->is_active);
    }
}
