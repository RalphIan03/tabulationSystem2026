<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CompetitionScoringMethod;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CompetitionBracketTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_users_can_create_win_based_contests_with_a_bracket_link(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);

        $this->actingAs($user)->post(route('events.categories.competitions.store', [$event, $category]), [
            'competition_name' => 'Basketball',
            'scoring_method' => 'wins',
            'bracket_url' => 'https://example.com/brackets/basketball?round=2&view=full',
        ])->assertRedirect(route('events.show', $event))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('competitions', [
            'category_id' => $category->id,
            'name' => 'Basketball',
            'scoring_method' => 'wins',
            'bracket_url' => 'https://example.com/brackets/basketball?round=2&view=full',
            'judge_count' => 1,
        ]);
    }

    #[DataProvider('optionalBracketPayloads')]
    public function test_bracket_link_is_optional_when_creating_a_win_based_contest(array $payload): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);

        $this->actingAs($user)->post(route('events.categories.competitions.store', [$event, $category]), [
            'competition_name' => 'Basketball',
            'scoring_method' => 'wins',
            ...$payload,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('competitions', ['category_id' => $category->id, 'name' => 'Basketball', 'bracket_url' => null]);
    }

    #[DataProvider('roles')]
    public function test_users_can_update_a_bracket_link_without_changing_scores_or_other_configuration(AccountRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$event, $category, $competition] = $this->contest();
        $participant = $event->participants()->create(['name' => 'TEAM A']);
        $result = $competition->results()->create(['participant_id' => $participant->id, 'wins' => 3, 'deduction' => 5]);

        $this->actingAs($user)->patch(route('events.categories.competitions.bracket.update', [$event, $category, $competition]), [
            'bracket_url' => 'http://example.com/updated-bracket',
            'name' => 'Unexpected rename',
            'scoring_method' => 'criteria',
        ])->assertRedirect(route('events.categories.competitions.show', [$event, $category, $competition]))
            ->assertSessionHas('status', 'Contest bracket link updated successfully.');

        $this->assertSame('http://example.com/updated-bracket', $competition->refresh()->bracket_url);
        $this->assertSame('Basketball', $competition->name);
        $this->assertSame(CompetitionScoringMethod::Wins, $competition->scoring_method);
        $this->assertSame(3, $result->refresh()->wins);
        $this->assertSame('5.00', $result->deduction);
        $log = ActivityLog::query()->where('route_name', 'events.categories.competitions.bracket.update')->latest('id')->firstOrFail();
        $this->assertSame('Updated a contest bracket link', $log->description);
        $this->assertSame($competition->id, $log->context['resources']['competition']['id']);
    }

    public function test_clearing_the_bracket_link_removes_it_from_configuration_and_scoring(): void
    {
        $user = User::factory()->create();
        [$event, $category, $competition] = $this->contest();
        $competition->update(['bracket_url' => 'https://example.com/old-bracket']);

        $this->actingAs($user)->patch(route('events.categories.competitions.bracket.update', [$event, $category, $competition]), [
            'bracket_url' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($competition->refresh()->bracket_url);
        $this->get(route('events.categories.competitions.show', [$event, $category, $competition]))->assertDontSee('Open bracket');
        $this->get(route('events.categories.competitions.scores.edit', [$event, $category, $competition]))->assertDontSee('Open bracket');
    }

    public function test_saved_bracket_is_an_escaped_external_link_on_configuration_and_score_pages(): void
    {
        $user = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event, $category, $competition] = $this->contest();
        $competition->update(['bracket_url' => 'https://example.com/bracket?round=2&view=full']);
        $parameters = [$event, $category, $competition];

        $this->actingAs($user)->get(route('events.categories.competitions.show', $parameters))
            ->assertSee('href="https://example.com/bracket?round=2&amp;view=full" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('action="'.route('events.categories.competitions.bracket.update', $parameters, false).'"', false);
        $this->get(route('events.categories.competitions.scores.edit', $parameters))
            ->assertSee('href="https://example.com/bracket?round=2&amp;view=full" target="_blank" rel="noopener noreferrer"', false);
    }

    #[DataProvider('invalidBracketUrls')]
    public function test_invalid_bracket_urls_are_rejected_without_changing_the_saved_link(mixed $url, string $message): void
    {
        $user = User::factory()->create();
        [$event, $category, $competition] = $this->contest();
        $competition->update(['bracket_url' => 'https://example.com/original']);

        $this->actingAs($user)->patch(route('events.categories.competitions.bracket.update', [$event, $category, $competition]), [
            'bracket_url' => $url,
        ])->assertSessionHasErrors(['bracket_url' => $message]);

        $this->assertSame('https://example.com/original', $competition->refresh()->bracket_url);
    }

    public function test_invalid_bracket_url_prevents_contest_creation(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);

        $this->actingAs($user)->post(route('events.categories.competitions.store', [$event, $category]), [
            'competition_name' => 'Basketball', 'scoring_method' => 'wins', 'bracket_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('bracket_url');

        $this->assertDatabaseCount('competitions', 0);
    }

    public function test_update_requires_the_bracket_field_to_prevent_accidental_clearing(): void
    {
        $user = User::factory()->create();
        [$event, $category, $competition] = $this->contest();
        $competition->update(['bracket_url' => 'https://example.com/original']);

        $this->actingAs($user)->patch(route('events.categories.competitions.bracket.update', [$event, $category, $competition]), [])
            ->assertSessionHasErrors('bracket_url');

        $this->assertSame('https://example.com/original', $competition->refresh()->bracket_url);
    }

    #[DataProvider('nonWinMethods')]
    public function test_bracket_settings_are_unavailable_for_other_scoring_methods(CompetitionScoringMethod $method): void
    {
        $user = User::factory()->create();
        [$event, $category, $competition] = $this->contest();
        $competition->update(['scoring_method' => $method, 'bracket_url' => 'https://example.com/preserved']);

        $this->actingAs($user)->patch(route('events.categories.competitions.bracket.update', [$event, $category, $competition]), [
            'bracket_url' => 'https://example.com/changed',
        ])->assertForbidden();

        $this->assertSame('https://example.com/preserved', $competition->refresh()->bracket_url);
        $this->get(route('events.categories.competitions.show', [$event, $category, $competition]))
            ->assertDontSee('Save bracket link')->assertDontSee('Open bracket');
        $this->get(route('events.categories.competitions.scores.edit', [$event, $category, $competition]))->assertDontSee('Open bracket');
    }

    #[DataProvider('nonWinMethods')]
    public function test_non_win_contest_creation_ignores_bracket_urls(CompetitionScoringMethod $method): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Arts']);

        $this->actingAs($user)->post(route('events.categories.competitions.store', [$event, $category]), [
            'competition_name' => 'Contest', 'scoring_method' => $method->value, 'judge_count' => 2, 'bracket_url' => 'javascript:alert(1)',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('competitions', ['category_id' => $category->id, 'name' => 'Contest', 'bracket_url' => null]);
    }

    public function test_finalization_prevents_auditor_bracket_changes_but_allows_admin_corrections(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $category, $competition] = $this->contest();
        $competition->update(['scores_finalized_at' => now()]);
        $parameters = [$event, $category, $competition];

        $this->actingAs($auditor)->patch(route('events.categories.competitions.bracket.update', $parameters), ['bracket_url' => 'https://example.com/bracket'])->assertForbidden();
        $this->assertNull($competition->refresh()->bracket_url);
        $this->get(route('events.categories.competitions.show', $parameters))->assertDontSee('Save bracket link');
        $this->actingAs($admin)->patch(route('events.categories.competitions.bracket.update', $parameters), ['bracket_url' => 'https://example.com/bracket'])->assertRedirect();

        $this->assertSame('https://example.com/bracket', $competition->refresh()->bracket_url);
        $this->assertNotNull($competition->scores_finalized_at);
    }

    public function test_guests_cannot_update_bracket_links(): void
    {
        [$event, $category, $competition] = $this->contest();

        $this->patch(route('events.categories.competitions.bracket.update', [$event, $category, $competition]), ['bracket_url' => 'https://example.com/bracket'])
            ->assertRedirect(route('login'));

        $this->assertNull($competition->refresh()->bracket_url);
    }

    public function test_a_contest_cannot_be_updated_through_another_event_or_category(): void
    {
        $user = User::factory()->create();
        [$event, $category, $competition] = $this->contest();
        $otherEvent = Event::factory()->create();
        $otherCategory = $event->categories()->create(['name' => 'Other sports']);

        $this->actingAs($user)->patch(route('events.categories.competitions.bracket.update', [$otherEvent, $category, $competition]), ['bracket_url' => 'https://example.com/bracket'])->assertNotFound();
        $this->patch(route('events.categories.competitions.bracket.update', [$event, $otherCategory, $competition]), ['bracket_url' => 'https://example.com/bracket'])->assertNotFound();

        $this->assertNull($competition->refresh()->bracket_url);
    }

    public function test_archived_event_bracket_links_cannot_be_changed(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        [$event, $category, $competition] = $this->contest();
        $event->update(['archived_at' => now(), 'is_active' => false]);

        $this->actingAs($admin)->patch(route('events.categories.competitions.bracket.update', [$event, $category, $competition]), ['bracket_url' => 'https://example.com/bracket'])->assertForbidden();

        $this->assertNull($competition->refresh()->bracket_url);
    }

    public function test_inactive_events_are_not_accessible_to_auditors_for_bracket_changes(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        [$event, $category, $competition] = $this->contest();
        $event->update(['is_active' => false]);

        $this->actingAs($auditor)->patch(route('events.categories.competitions.bracket.update', [$event, $category, $competition]), ['bracket_url' => 'https://example.com/bracket'])->assertNotFound();

        $this->assertNull($competition->refresh()->bracket_url);
    }

    public static function roles(): array
    {
        return ['admin' => [AccountRole::Admin], 'auditor' => [AccountRole::Auditor]];
    }

    public static function optionalBracketPayloads(): array
    {
        return ['missing' => [[]], 'empty' => [['bracket_url' => '']], 'null' => [['bracket_url' => null]]];
    }

    public static function nonWinMethods(): array
    {
        return ['criteria' => [CompetitionScoringMethod::Criteria], 'ranking' => [CompetitionScoringMethod::Ranking]];
    }

    public static function invalidBracketUrls(): array
    {
        $urlMessage = 'The bracket url field must be a valid URL.';

        return [
            'javascript' => ['javascript:alert(1)', $urlMessage],
            'data' => ['data:text/html,<script>alert(1)</script>', $urlMessage],
            'ftp' => ['ftp://example.com/bracket', $urlMessage],
            'relative' => ['/bracket', $urlMessage],
            'protocol relative' => ['//example.com/bracket', $urlMessage],
            'invalid' => ['not a url', $urlMessage],
            'array' => [['https://example.com/bracket'], 'The bracket url field must be a string.'],
            'too long' => ['https://example.com/'.str_repeat('a', 2048), 'The bracket url field must not be greater than 2048 characters.'],
        ];
    }

    /** @return array{Event, Category, Competition} */
    private function contest(): array
    {
        $event = Event::factory()->create();
        $category = $event->categories()->create(['name' => 'Sports']);
        $competition = $category->competitions()->create(['name' => 'Basketball', 'scoring_method' => CompetitionScoringMethod::Wins]);

        return [$event, $category, $competition];
    }
}
