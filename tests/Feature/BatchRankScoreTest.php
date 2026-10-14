<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BatchRankScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_rank_scores_are_saved_in_one_request_and_existing_ranks_are_updated(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Race']);
        $competition->rankScores()->create(['rank' => 1, 'points' => 50]);
        $routeParameters = [$event, $category, $competition];

        $this->actingAs($admin)
            ->get(route('events.categories.competitions.show', $routeParameters))
            ->assertSee('data-rank-scores-form', false)
            ->assertSee('data-rank-score-add', false)
            ->assertSee('name="rank_scores[0][rank]"', false);

        $this->actingAs($admin)
            ->post(route('events.categories.competitions.rank-scores.store', $routeParameters), [
                'rank_scores' => [
                    ['rank' => 1, 'points' => 100],
                    ['rank' => 2, 'points' => 75],
                    ['rank' => 3, 'points' => 25],
                ],
            ])
            ->assertRedirect(route('events.categories.competitions.show', $routeParameters))
            ->assertSessionHas('status', 'Rank scores saved successfully.');

        $this->assertSame(3, $competition->rankScores()->count());
        $this->assertDatabaseHas('rank_scores', ['competition_id' => $competition->id, 'rank' => 1, 'points' => 100]);
        $this->assertDatabaseHas('rank_scores', ['competition_id' => $competition->id, 'rank' => 2, 'points' => 75]);
        $this->assertDatabaseHas('rank_scores', ['competition_id' => $competition->id, 'rank' => 3, 'points' => 25]);
    }

    public function test_duplicate_or_invalid_ranks_reject_the_entire_batch(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Race']);
        $route = route('events.categories.competitions.rank-scores.store', [$event, $category, $competition]);

        $this->actingAs($admin)->post($route, [
            'rank_scores' => [
                ['rank' => 1, 'points' => 100],
                ['rank' => 1, 'points' => 75],
            ],
        ])->assertSessionHasErrors('rank_scores.0.rank');

        $this->assertSame(0, $competition->rankScores()->count());

        $this->actingAs($admin)->post($route, [
            'rank_scores' => [
                ['rank' => 1, 'points' => 100],
                ['rank' => 2, 'points' => -1],
            ],
        ])->assertSessionHasErrors('rank_scores.1.points');

        $this->assertSame(0, $competition->rankScores()->count());
    }

    public function test_auditor_can_save_rank_scores_for_an_active_contest(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Race']);

        $this->actingAs($auditor)->post(
            route('events.categories.competitions.rank-scores.store', [$event, $category, $competition]),
            ['rank_scores' => [['rank' => 1, 'points' => 10], ['rank' => 2, 'points' => 5]]],
        )->assertRedirect();

        $this->assertSame(2, $competition->rankScores()->count());
    }
}
