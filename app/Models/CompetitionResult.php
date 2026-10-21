<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CompetitionResult extends Model
{
    protected $fillable = ['participant_id', 'has_entry', 'wins', 'placement', 'deduction'];

    protected function casts(): array
    {
        return [
            'has_entry' => 'boolean',
            'wins' => 'integer',
            'placement' => 'integer',
            'deduction' => 'decimal:2',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function criterionScores(): HasMany
    {
        return $this->hasMany(CriterionScore::class);
    }

    public function judgeScoreTotal(Competition $competition, int $judgeNumber): ?float
    {
        if ($judgeNumber < 1 || $judgeNumber > $competition->judge_count || $competition->criteria->isEmpty()) {
            return null;
        }

        $scores = $this->criterionScores->where('judge_number', $judgeNumber)->keyBy('criterion_id');
        $total = 0.0;

        foreach ($competition->criteria as $criterion) {
            $score = $scores->get($criterion->getKey());

            if ($score === null) {
                return null;
            }

            $total += (float) $score->score;
        }

        return round($total, 2);
    }

    /** @return array{averages: array<int, float|null>, gross: float|null, rankable: bool} */
    public function criteriaScoreSummary(Competition $competition): array
    {
        $scoresByCriterion = $this->criterionScores
            ->filter(fn (CriterionScore $score): bool => $score->judge_number >= 1
                && $score->judge_number <= $competition->judge_count)
            ->groupBy('criterion_id');
        $averages = [];

        foreach ($competition->criteria as $criterion) {
            $judgeScores = $scoresByCriterion->get($criterion->getKey(), collect())->unique('judge_number');
            $averages[$criterion->getKey()] = $judgeScores->count() === $competition->judge_count
                ? round($judgeScores->sum(static fn (CriterionScore $score): float => (float) $score->score) / $competition->judge_count, 2)
                : null;
        }

        $hasAverage = count(array_filter($averages, static fn (?float $average): bool => $average !== null)) > 0;
        $rankable = $competition->judge_count === 1
            ? $hasAverage
            : $competition->criteria->isNotEmpty() && ! in_array(null, $averages, true);

        return [
            'averages' => $averages,
            'gross' => $rankable ? round(array_sum($averages), 2) : null,
            'rankable' => $rankable,
        ];
    }
}
