<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Str;

final class EventResultsExporter
{
    public function __construct(private readonly LeaderboardCalculator $leaderboard) {}

    public function filename(Event $event): string
    {
        return Str::slug($event->name).'-'.$event->getKey().'-results.csv';
    }

    /** @param resource $stream */
    public function write($stream, Event $event): void
    {
        $event->load([
            'categories' => fn ($query) => $query->orderBy('name'),
            'categories.competitions' => fn ($query) => $query->orderBy('name'),
            'categories.competitions.criteria',
            'categories.competitions.rankScores',
            'categories.competitions.results.participant.parentTeam',
            'categories.competitions.results.criterionScores',
        ]);

        fwrite($stream, "\xEF\xBB\xBF");
        $this->row($stream, ['Event', $event->name]);
        $this->row($stream, ['Inclusive dates', $event->start_date->format('Y-m-d').' to '.$event->end_date->format('Y-m-d')]);
        $this->row($stream, []);
        $this->row($stream, ['Overall results']);
        $this->row($stream, ['Rank', 'Participant', 'Contest wins', 'Recorded wins', 'Leaderboard points', 'Contests entered', 'Earned points', 'Overall deduction', 'Finalized points', 'Pending points']);

        foreach ($this->leaderboard->overallStandings($event, rankByPoints: true) as $index => $result) {
            $this->row($stream, [
                $index + 1,
                $result['participant']->name,
                $result['contest_wins'],
                $result['recorded_wins'],
                $result['leaderboard_points'],
                $result['contests_entered'],
                $result['gross_points'],
                $result['overall_deduction'],
                $result['finalized_points'],
                $result['pending_points'],
            ]);
        }

        foreach ($event->categories as $category) {
            foreach ($category->competitions as $competition) {
                $this->row($stream, []);
                $this->row($stream, ['Category', $category->name, 'Contest', $competition->name]);
                $this->row($stream, ['Score status', $competition->scoresAreFinalized() ? 'Finalized' : 'Not finalized']);
                $this->row($stream, $competition->usesCriteriaScoring()
                    ? ['Rank', 'Participant', 'Gross score', 'Deduction', 'Final score', 'Wins', 'Leaderboard points']
                    : ($competition->usesRankingScoring()
                        ? ['Rank', 'Participant', 'Entered rank', 'Rank points', 'Deduction', 'Leaderboard points']
                        : ['Rank', 'Participant', 'Wins', 'Rank points', 'Deduction', 'Leaderboard points']));

                foreach ($this->leaderboard->competitionStandings($competition, $event) as $result) {
                    $this->row($stream, $competition->usesCriteriaScoring()
                        ? [
                            $result['rank'],
                            $result['participant']->name,
                            $result['gross_score'],
                            $result['deduction'],
                            $result['raw_score'],
                            $result['wins'],
                            $result['leaderboard_points'],
                        ]
                        : [
                            $result['rank'],
                            $result['participant']->name,
                            $competition->usesRankingScoring() ? $result['placement'] : $result['wins'],
                            $result['rank_points'],
                            $result['deduction'],
                            $result['leaderboard_points'],
                        ]);
                }
            }
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<int, int|float|string|null>  $values
     */
    private function row($stream, array $values): void
    {
        fputcsv($stream, array_map(static function (int|float|string|null $value): int|float|string|null {
            if (is_string($value) && preg_match('/^\s*[=+\-@]/u', $value)) {
                return "'".$value;
            }

            return $value;
        }, $values));
    }
}
