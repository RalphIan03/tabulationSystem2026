<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\Participant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PublicLeaderboardData
{
    public function __construct(
        private readonly LeaderboardCalculator $calculator,
        private readonly EventReadCache $cache,
    ) {}

    /** @return array{rows: array<int, array<string, float|int|string|null>>, categories: array<int, array<string, mixed>>} */
    public function snapshot(Event $event, ?int $contestCategoryId = null): array
    {
        if ($contestCategoryId !== null) {
            $event->categories()->findOrFail($contestCategoryId);
        }

        $snapshot = $this->cache->leaderboard($event, fn (): array => $this->buildSnapshot($event));

        if ($contestCategoryId !== null) {
            $snapshot['categories'] = array_values(array_filter(
                $snapshot['categories'],
                static fn (array $category): bool => $category['id'] === $contestCategoryId,
            ));
        }

        return $snapshot;
    }

    /** @return array{rows: array<int, array<string, float|int|string|null>>, categories: array<int, array<string, mixed>>} */
    private function buildSnapshot(Event $event): array
    {
        $event->load([
            'categories' => fn ($query) => $query->orderBy('name'),
            'categories.competitions' => fn ($query) => $query->whereNotNull('scores_finalized_at')->orderBy('name'),
            'categories.competitions.rankScores',
            'categories.competitions.criteria',
            'categories.competitions.results' => fn ($query) => $query->where('has_entry', true),
            'categories.competitions.results.participant',
            'categories.competitions.results.criterionScores',
        ]);

        $rows = $this->calculator->overallStandings($event)
            ->map(fn (array $row, int $index): array => [
                'rank' => $index + 1,
                ...$this->participantColumns($row['participant']),
                'contest_wins' => $row['contest_wins'],
                'points' => $row['leaderboard_points'],
            ])->all();
        $categories = $event->categories
            ->map(fn ($category): array => [
                'id' => $category->getKey(),
                'name' => $category->name,
                'contests' => $category->competitions->map(fn ($competition): array => [
                    'id' => $competition->getKey(),
                    'name' => $competition->name,
                    'method' => $competition->scoring_method->value,
                    'rows' => $this->calculator->competitionStandings($competition, $event)
                        ->map(fn (array $row): array => [
                            'rank' => $row['rank'],
                            ...$this->participantColumns($row['participant']),
                            'result' => $competition->usesCriteriaScoring()
                                ? number_format($row['raw_score'], 2, '.', '')
                                : $row['wins'].' '.Str::plural('win', $row['wins']),
                            'points' => $row['leaderboard_points'],
                        ])->all(),
                ])->all(),
            ])->values()->all();

        return compact('rows', 'categories');
    }

    /** @return list<array<string, float|int|string|null>> */
    public function rows(Event $event, string $scope, ?int $categoryId, ?int $competitionId): array
    {
        if ($scope === 'game') {
            $competition = $event->competitions()->findOrFail($competitionId);

            if (! $competition->scoresAreFinalized()) {
                return [];
            }

            $competition->load([
                'criteria',
                'rankScores',
                'results' => fn ($query) => $query->where('has_entry', true),
                'results.participant',
                'results.criterionScores',
            ]);

            return $this->calculator->competitionStandings($competition, $event)
                ->map(fn (array $row): array => [
                    'rank' => $row['rank'],
                    ...$this->participantColumns($row['participant']),
                    'result' => $competition->usesCriteriaScoring()
                        ? number_format($row['raw_score'], 2, '.', '')
                        : $row['wins'].' '.Str::plural('win', $row['wins']),
                    'points' => $row['leaderboard_points'],
                ])->all();
        }

        if ($scope === 'category') {
            $event->categories()->findOrFail($categoryId);
        }

        $event->load([
            'categories' => fn ($query) => $query
                ->when($scope === 'category', fn ($query) => $query->whereKey($categoryId))
                ->orderBy('name'),
            'categories.competitions' => fn ($query) => $query
                ->whereNotNull('scores_finalized_at')
                ->orderBy('name'),
            'categories.competitions.rankScores',
            'categories.competitions.criteria',
            'categories.competitions.results' => fn ($query) => $query->where('has_entry', true),
            'categories.competitions.results.participant',
            'categories.competitions.results.criterionScores',
        ]);

        return $this->calculator->overallStandings($event)
            ->map(fn (array $row, int $index): array => [
                'rank' => $index + 1,
                ...$this->participantColumns($row['participant']),
                'contest_wins' => $row['contest_wins'],
                'points' => $row['leaderboard_points'],
            ])->all();
    }

    /** @return array{name: string, profile_image_url: ?string} */
    private function participantColumns(Participant $participant): array
    {
        return [
            'name' => $participant->name,
            'profile_image_url' => $participant->profile_picture_path === null
                ? null
                : Storage::disk('public')->url($participant->profile_picture_path),
        ];
    }
}
