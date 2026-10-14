<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RelativeFormActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rendered_internal_forms_keep_relative_destinations(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Arts']);
        $competition = $category->competitions()->create(['name' => 'Painting']);
        $criterion = $competition->criteria()->create(['name' => 'Technique', 'max_score' => 100]);
        $competition->rankScores()->create(['rank' => 1, 'points' => 10]);
        $participant = $event->participants()->create(['name' => 'Alex']);

        $login = $this->get('/login')->assertSee('action="/login"', false);
        $this->assertRelativeFormActions($login->getContent());

        $this->actingAs($admin);
        $pages = [
            $this->get('/events')->assertOk(),
            $this->get(route('events.show', $event))->assertOk(),
            $this->get(route('events.categories.competitions.show', [$event, $category, $competition]))->assertOk(),
            $this->get('/users')->assertOk(),
            $this->get('/logs')->assertOk(),
        ];

        foreach ($pages as $page) {
            $this->assertRelativeFormActions($page->getContent());
        }

        $result = $competition->results()->create(['participant_id' => $participant->id]);
        $result->criterionScores()->create(['criterion_id' => $criterion->id, 'score' => 85]);
        $scores = $this->get(route('events.categories.competitions.scores.edit', [$event, $category, $competition]))
            ->assertOk()
            ->assertSee('action="/events/'.$event->id.'/categories/'.$category->id.'/competitions/'.$competition->id.'/scores"', false);

        $this->assertRelativeFormActions($scores->getContent());
    }

    private function assertRelativeFormActions(string $html): void
    {
        preg_match_all('/<form\b[^>]*\baction="([^"]+)"/i', $html, $matches);
        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $action) {
            $this->assertMatchesRegularExpression('~^/(?!/)~', $action);
        }
    }
}
