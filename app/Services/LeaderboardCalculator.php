<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TieRankingMethod;
use App\Models\Competition;
use App\Models\Criterion;
use App\Models\Event;
use App\Models\Participant;
use Illuminate\Support\Collection;

final class LeaderboardCalculator
{
    /**
     * @return Collection<int, array{
     *     participant: Participant,
     *     rank: int,
     *     gross_score: float,
     *     deduction: float,
     *     raw_score: float,
     *     wins: ?int,
     *     placement: ?int,
     *     rank_points: float,
     *     leaderboard_points: float
     * }>
     */
    public function competitionStandings(Competition $competition, ?Event $event = null): Collection
    {
        if ($event === null) {
            $competition->loadMissing('category.event');
            $event = $competition->category->event;
        }

        if (! $this->competitionRelationsAreLoaded($competition)) {
            $competition->load([
                'criteria',
                'rankScores',
                'results.participant.parentTeam',
                'results.criterionScores',
            ]);
        }

        $rows = $competition->results
            ->filter(fn ($result): bool => $result->has_entry
                && ($competition->usesCriteriaScoring()
                    ? $result->criteriaScoreSummary($competition)['rankable']
                    : ($competition->usesRankingScoring() ? $result->placement !== null : $result->wins !== null)))
            ->map(function ($result) use ($competition): array {
                $wins = $competition->usesCriteriaScoring() || $competition->usesRankingScoring()
                    ? null
                    : (int) $result->wins;
                $placement = $competition->usesRankingScoring() ? (int) $result->placement : null;
                $grossScore = $competition->usesCriteriaScoring()
                    ? (float) $result->criteriaScoreSummary($competition)['gross']
                    : (float) ($placement ?? $wins);
                $deduction = (float) $result->deduction;

                return [
                    'participant' => $result->participant,
                    'rank' => 0,
                    'gross_score' => $grossScore,
                    'deduction' => $deduction,
                    'raw_score' => $competition->usesCriteriaScoring()
                        ? max(0.0, $grossScore - $deduction)
                        : $grossScore,
                    'wins' => $wins,
                    'placement' => $placement,
                    'rank_points' => 0.0,
                    'leaderboard_points' => 0.0,
                ];
            })
            ->sort(function (array $left, array $right) use ($competition): int {
                $scoreComparison = $competition->usesRankingScoring()
                    ? $left['placement'] <=> $right['placement']
                    : $right['raw_score'] <=> $left['raw_score'];

                return $scoreComparison !== 0
                    ? $scoreComparison
                    : strcasecmp($left['participant']->name, $right['participant']->name);
            })
            ->values();

        $previousScore = null;
        $rank = 0;
        $tieRankingMethod = $event->tie_ranking_method;
        $rankScores = $competition->rankScores->keyBy('rank');

        return $rows->map(function (array $row, int $index) use (
            $competition,
            $rankScores,
            $tieRankingMethod,
            &$previousScore,
            &$rank,
        ): array {
            if ($competition->usesRankingScoring()) {
                $rank = $row['placement'];
            } elseif ($previousScore === null || abs($row['raw_score'] - $previousScore) > 0.00001) {
                $rank = $tieRankingMethod === TieRankingMethod::SkipPositions
                    ? $index + 1
                    : $rank + 1;
            }

            $rankScore = $rankScores->get($rank);
            $row['rank'] = $rank;
            $row['rank_points'] = $rankScore === null
                ? (float) $competition->non_podium_points
                : (float) $rankScore->points;
            $row['leaderboard_points'] = $competition->usesCriteriaScoring()
                ? $row['rank_points']
                : max(0.0, $row['rank_points'] - $row['deduction']);
            $previousScore = $row['raw_score'];

            return $row;
        });
    }

    /** @return Collection<int, array{criterion: Criterion, standings: Collection<int, array{participant: Participant, score: float, rank: int}>}> */
    public function criterionStandings(Competition $competition, Event $event): Collection
    {
        if (! $competition->usesCriteriaScoring()) {
            return collect();
        }

        $competition->loadMissing(['criteria', 'results.participant', 'results.criterionScores']);

        return $competition->criteria->sortBy('name')->values()->map(function ($criterion) use ($competition, $event): array {
            $rows = $competition->results
                ->filter(static fn ($result): bool => $result->has_entry)
                ->map(function ($result) use ($competition, $criterion): ?array {
                    $average = $result->criteriaScoreSummary($competition)['averages'][$criterion->getKey()] ?? null;

                    return $average === null ? null : [
                        'participant' => $result->participant,
                        'score' => $average,
                        'rank' => 0,
                    ];
                })
                ->filter()
                ->sort(static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
                    ?: strcasecmp($left['participant']->name, $right['participant']->name))
                ->values();
            $previousScore = null;
            $rank = 0;

            return [
                'criterion' => $criterion,
                'standings' => $rows->map(function (array $row, int $index) use ($event, &$previousScore, &$rank): array {
                    if ($previousScore === null || abs($row['score'] - $previousScore) > 0.00001) {
                        $rank = $event->tie_ranking_method === TieRankingMethod::SkipPositions
                            ? $index + 1
                            : $rank + 1;
                    }

                    $row['rank'] = $rank;
                    $previousScore = $row['score'];

                    return $row;
                }),
            ];
        });
    }

    /**
     * @return Collection<int, array{
     *     participant: Participant,
     *     contest_wins: int,
     *     recorded_wins: int,
     *     leaderboard_points: float,
     *     contests_entered: int
     * }>
     */
    public function overallStandings(Event $event): Collection
    {
        if (! $this->eventRelationsAreLoaded($event)) {
            $event->load([
                'categories.competitions.criteria',
                'categories.competitions.rankScores',
                'categories.competitions.results.participant.parentTeam',
                'categories.competitions.results.criterionScores',
            ]);
        }

        $totals = [];

        foreach ($event->categories as $category) {
            foreach ($category->competitions as $competition) {
                $enteredTeams = [];
                $winningTeams = [];

                foreach ($this->competitionStandings($competition, $event) as $row) {
                    $participant = $row['participant']->parentTeam ?? $row['participant'];
                    $participantId = (string) $participant->getKey();
                    $totals[$participantId] ??= [
                        'participant' => $participant,
                        'contest_wins' => 0,
                        'recorded_wins' => 0,
                        'leaderboard_points' => 0.0,
                        'contests_entered' => 0,
                    ];

                    if ($row['rank'] === 1 && ! isset($winningTeams[$participantId])) {
                        $totals[$participantId]['contest_wins']++;
                        $winningTeams[$participantId] = true;
                    }

                    $totals[$participantId]['recorded_wins'] += $row['wins'] ?? 0;
                    $totals[$participantId]['leaderboard_points'] += $row['leaderboard_points'];

                    if (! isset($enteredTeams[$participantId])) {
                        $totals[$participantId]['contests_entered']++;
                        $enteredTeams[$participantId] = true;
                    }
                }
            }
        }

        return collect(array_values($totals))
            ->sort(function (array $left, array $right): int {
                return ($right['contest_wins'] <=> $left['contest_wins'])
                    ?: ($right['recorded_wins'] <=> $left['recorded_wins'])
                    ?: ($right['leaderboard_points'] <=> $left['leaderboard_points'])
                    ?: strcasecmp($left['participant']->name, $right['participant']->name);
            })
            ->values();
    }

    private function competitionRelationsAreLoaded(Competition $competition): bool
    {
        if (($competition->usesCriteriaScoring() && ! $competition->relationLoaded('criteria'))
            || ! $competition->relationLoaded('rankScores')
            || ! $competition->relationLoaded('results')) {
            return false;
        }

        return $competition->results->every(
            static fn ($result): bool => $result->relationLoaded('participant')
                && $result->participant->relationLoaded('parentTeam')
                && $result->relationLoaded('criterionScores'),
        );
    }

    private function eventRelationsAreLoaded(Event $event): bool
    {
        if (! $event->relationLoaded('categories')) {
            return false;
        }

        return $event->categories->every(function ($category): bool {
            if (! $category->relationLoaded('competitions')) {
                return false;
            }

            return $category->competitions->every(
                fn (Competition $competition): bool => $this->competitionRelationsAreLoaded(
                    $competition,
                ),
            );
        });
    }
}
