<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\User;
use App\Services\PublicLeaderboardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CategoryDeletionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_both_roles_can_delete_empty_categories_and_refresh_the_leaderboard(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Empty category']);
        $remaining = $event->categories()->create(['name' => 'Remaining category']);
        $participant = $event->participants()->create(['name' => 'TEAM A']);
        $leaderboard = app(PublicLeaderboardData::class);
        $leaderboard->snapshot($event);

        $this->actingAs($user)->get(route('events.show', $event))
            ->assertSee('action="'.route('events.categories.destroy', [$event, $category], false).'"', false)
            ->assertSee('Delete this empty category? This cannot be undone.')
            ->assertSee('data-loading-text="Deleting category…"', false);

        $this->delete(route('events.categories.destroy', [$event, $category]))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHas('status', 'Category deleted successfully.');

        $this->assertModelMissing($category);
        $this->assertModelExists($remaining);
        $this->assertModelExists($event);
        $this->assertModelExists($participant);
        $this->assertSame(['Remaining category'], array_column($leaderboard->snapshot($event)['categories'], 'name'));

        $log = ActivityLog::query()->where('action', 'events.categories.destroy')->sole();
        $this->assertContains([
            'operation' => 'deleted',
            'type' => 'Category',
            'id' => (string) $category->id,
            'label' => 'Empty category',
        ], $log->affectedRecords());
    }

    #[DataProvider('roles')]
    public function test_categories_containing_contests_are_protected_for_both_roles(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting', 'scores_finalized_at' => now()]);
        $criterion = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $rankScore = $competition->rankScores()->create(['rank' => 1, 'points' => 10]);
        $participant = $event->participants()->create(['name' => 'TEAM A']);
        $result = $competition->results()->create(['participant_id' => $participant->id]);
        $score = $result->criterionScores()->create(['criterion_id' => $criterion->id, 'score' => 90]);

        $this->actingAs($user)->get(route('events.show', $event))
            ->assertDontSee('action="'.route('events.categories.destroy', [$event, $category], false).'"', false)
            ->assertSee('Only empty categories can be deleted.');

        $this->from(route('events.show', $event))
            ->delete(route('events.categories.destroy', [$event, $category]))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasErrors([
                'category' => 'Only empty categories can be deleted. This category still contains contests.',
            ]);

        $this->assertModelExists($category);
        $this->assertModelExists($competition);
        $this->assertModelExists($criterion);
        $this->assertModelExists($rankScore);
        $this->assertModelExists($result);
        $this->assertModelExists($score);
    }

    public function test_an_unscored_contest_also_prevents_category_deletion(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting']);

        $this->actingAs($admin)->delete(route('events.categories.destroy', [$event, $category]))
            ->assertSessionHasErrors('category');

        $this->assertModelExists($category);
        $this->assertModelExists($competition);
    }

    public function test_a_category_cannot_be_deleted_through_another_event(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();
        $category = $otherEvent->categories()->create(['name' => 'Other category']);

        $this->actingAs($admin)->delete(route('events.categories.destroy', [$event, $category]))
            ->assertNotFound();

        $this->assertModelExists($category);
    }

    public function test_guests_cannot_delete_categories(): void
    {
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Arts']);

        $this->delete(route('events.categories.destroy', [$event, $category]))
            ->assertRedirect(route('login'));

        $this->assertModelExists($category);
    }

    public function test_admins_cannot_delete_categories_from_archived_events(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create(['archived_at' => now()]);
        $category = $event->categories()->create(['name' => 'Arts']);

        $this->actingAs($admin)->delete(route('events.categories.destroy', [$event, $category]))
            ->assertForbidden();

        $this->assertModelExists($category);
    }

    public function test_auditors_cannot_delete_categories_from_inactive_events(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create(['is_active' => false]);
        $category = $event->categories()->create(['name' => 'Arts']);

        $this->actingAs($auditor)->delete(route('events.categories.destroy', [$event, $category]))
            ->assertNotFound();

        $this->assertModelExists($category);
    }

    /** @return array<string, array{AccountRole}> */
    public static function roles(): array
    {
        return [
            'admin' => [AccountRole::Admin],
            'auditor' => [AccountRole::Auditor],
        ];
    }
}
