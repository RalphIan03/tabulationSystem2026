<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Criterion;
use App\Models\Event;
use App\Models\Participant;
use App\Models\RankScore;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class EventReadCache
{
    /** @param Closure(): array<string, mixed> $resolver
     * @return array<string, mixed>
     */
    public function leaderboard(Event $event, Closure $resolver): array
    {
        return Cache::remember($this->leaderboardKey((int) $event->getKey()), 120, $resolver);
    }

    /** @param Closure(): Collection<int, Event> $resolver
     * @return Collection<int, Event>
     */
    public function adminEvents(Closure $resolver): Collection
    {
        return $this->events('dashboard:admin:events:v2', $resolver);
    }

    /** @param Closure(): Collection<int, Event> $resolver
     * @return Collection<int, Event>
     */
    public function auditorEvents(Closure $resolver): Collection
    {
        return $this->events($this->auditorEventsKey(), $resolver);
    }

    public function invalidateModel(Model $model): void
    {
        if ($model instanceof Event) {
            $this->invalidateEvent((int) $model->getKey());

            return;
        }

        if ($model instanceof Category || $model instanceof Participant) {
            $this->invalidateEvent((int) $model->event_id);

            return;
        }

        if ($model instanceof Competition) {
            $eventId = Category::query()->whereKey($model->category_id)->value('event_id');

            if ($eventId !== null) {
                $this->invalidateEvent((int) $eventId);
            }

            return;
        }

        if ($model instanceof Criterion || $model instanceof RankScore) {
            $eventId = Competition::query()
                ->join('categories', 'categories.id', '=', 'competitions.category_id')
                ->where('competitions.id', $model->competition_id)
                ->value('categories.event_id');

            if ($eventId !== null) {
                $this->invalidateLeaderboard((int) $eventId);
            }
        }
    }

    public function invalidateEvent(int $eventId): void
    {
        $this->invalidateLeaderboard($eventId);
        Cache::forget('dashboard:admin:events:v2');
        Cache::forget($this->auditorEventsKey());
    }

    public function invalidateLeaderboard(int $eventId): void
    {
        Cache::forget($this->leaderboardKey($eventId));
    }

    private function leaderboardKey(int $eventId): string
    {
        return "leaderboard:event:{$eventId}:snapshot:v5";
    }

    private function auditorEventsKey(): string
    {
        return 'dashboard:auditor:events:'.today()->toDateString().':v2';
    }

    /** @param Closure(): Collection<int, Event> $resolver
     * @return Collection<int, Event>
     */
    private function events(string $key, Closure $resolver): Collection
    {
        $attributes = Cache::remember(
            $key,
            60,
            static fn (): array => $resolver()
                ->map(static fn (Event $event): array => $event->getAttributes())
                ->values()
                ->all(),
        );

        return Event::query()->hydrate($attributes);
    }
}
